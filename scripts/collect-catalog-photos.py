#!/usr/bin/env python3
"""Archive gallery originals and their exact legacy labels from the old site.

Run from the child theme: python3 scripts/collect-catalog-photos.py
Requires Pillow for image verification. Does not access the local WordPress DB.
"""

import argparse
import hashlib
import html
import io
import json
import re
import time
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode, urlparse
from urllib.request import Request, urlopen

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / 'project-files' / 'catalog-photos'
ORIGIN = 'https://www.juvelyrinisdarbas.lt'
SECTIONS = (
    ('ziedai', 'Vestuvinių ir sužadėtuvių žiedų gamyba',
     ORIGIN + '/vestuviniu-ir-suzadietuviu-ziedu-gamyba/'),
    ('kiti-gaminiai', 'Kitų gaminių gamyba',
     ORIGIN + '/kitu-gaminiu-gamyba/'),
)
LOCAL_GROUP_TITLES = {
    'vestuviniai-ziedai': 'Vestuviniai žiedai',
    'suzadetuviu-ziedai': 'Sužadėtuvių žiedai',
    'ziedai': 'Žiedai',
    'juvelyriniai-dirbiniai': 'Juvelyriniai dirbiniai',
    'kiti-gaminiai': 'Kiti gaminiai',
}


def reconcile_local_files(manifest, allow_missing=False):
    """Resolve moves by original SHA-256, never by a guessed product category."""
    files = sorted(p for p in (DEST / 'originals').rglob('*')
                   if p.is_file() and p.suffix.lower() in ('.jpg', '.jpeg', '.mpo', '.png', '.webp'))
    by_hash = {}
    for path in files:
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        by_hash.setdefault(digest, []).append(path)

    used = set()
    updates = []
    for item in manifest['images']:
        matches = by_hash.get(item.get('sha256'), [])
        old_path = DEST / item['local_file']
        if old_path in matches:
            actual = old_path
        elif len(matches) == 1:
            actual = matches[0]
        elif not matches and allow_missing and not old_path.exists():
            updates.append(dict(item))
            continue
        else:
            raise ValueError('Missing, modified or ambiguous original: ' + item['source_key'])
        if actual in used:
            raise ValueError('Two records point to the same local file: ' + str(actual))
        used.add(actual)
        group = actual.parent.relative_to(DEST / 'originals').as_posix()
        updates.append(dict(item, local_file=actual.relative_to(DEST).as_posix(),
                            local_group=group))

    return dict(manifest, images=updates,
                local_folder_counts=dict(sorted(Counter(
                    str(Path(item['local_file']).parent) for item in updates
                ).items())),
                unmapped_local_files=[p.relative_to(DEST).as_posix() for p in files if p not in used],
                local_layout_updated_at_utc=datetime.now(timezone.utc).isoformat())


def save_manifest(manifest):
    destination = DEST / 'manifest.json'
    temporary = destination.with_suffix('.json.tmp')
    temporary.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temporary.replace(destination)


def fetch(url):
    parsed = urlparse(url)
    if parsed.scheme != 'https' or parsed.hostname not in (
        'www.juvelyrinisdarbas.lt', 'juvelyrinisdarbas.lt'
    ):
        raise ValueError('Unexpected source host: ' + url)
    for attempt in range(3):
        try:
            request = Request(url, headers={
                'User-Agent': 'Mozilla/5.0 (compatible; JuvelyrinisDarbasPhotoArchive/1.0)'
            })
            with urlopen(request, timeout=35) as response:
                if urlparse(response.url).hostname not in (
                    'www.juvelyrinisdarbas.lt', 'juvelyrinisdarbas.lt'
                ):
                    raise ValueError('Unexpected redirect host')
                return response.read()
        except Exception:
            if attempt == 2:
                raise
            time.sleep(attempt + 1)


class GalleryParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.items = []
        self.current = None

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'a' and 'av-masonry-entry' in attrs.get('class', '').split():
            source_id = attrs.get('data-av-masonry-item', '')
            if source_id.isdigit() and attrs.get('href'):
                self.current = {
                    'source_attachment_id': int(source_id),
                    'gallery_image_url': attrs['href'],
                    'legacy_label': attrs.get('title', '').strip(),
                }
                self.items.append(self.current)
        if tag == 'img' and self.current is not None:
            self.current['thumbnail_url'] = attrs.get('src', '')
            self.current['original_alt'] = attrs.get('alt', '')

    def handle_endtag(self, tag):
        if tag == 'a':
            self.current = None


def plain_text(value):
    return html.unescape(re.sub(r'<[^>]+>', '', value)).strip()


def image_info(data):
    with Image.open(io.BytesIO(data)) as image:
        width, height = image.size
        # Some Samsung camera JPGs contain an MPO extension (multiple pictures).
        # Preserve those bytes as published; do not flatten/re-encode originals.
        image_format = image.format
        frame_count = getattr(image, 'n_frames', 1)
        if image_format not in ('JPEG', 'MPO'):
            raise ValueError('Expected a JPEG/MPO original, got ' + str(image_format))
        image.verify()
    return {'width': width, 'height': height, 'image_format': image_format,
            'frame_count': frame_count, 'bytes': len(data),
            'sha256': hashlib.sha256(data).hexdigest()}


def collect_item(item, previous):
    destination = DEST / item['local_file']
    try:
        if destination.exists():
            info = image_info(destination.read_bytes())
            old = previous.get(item['source_key'])
            if not old or old.get('sha256') != info['sha256']:
                raise ValueError('Existing file is not a verified previous download; not overwritten')
            return dict(item, **info, download_status='verified_existing')

        data = fetch(item['original_url'])
        info = image_info(data)
        if (info['width'], info['height']) != (
            item['source_width'], item['source_height']
        ):
            raise ValueError('Downloaded dimensions differ from source metadata')
        destination.parent.mkdir(parents=True, exist_ok=True)
        # Exclusive creation prevents replacement of files added during download.
        with destination.open('xb') as output:
            output.write(data)
        return dict(item, **info, download_status='downloaded')
    except Exception as error:
        return dict(item, download_status='error', error=str(error))


def write_index(manifest):
    esc = html.escape
    sections = []
    groups = {item.get('local_group', Path(item['local_file']).parent.name)
              for item in manifest['images']}
    ordered_groups = sorted(groups, key=lambda group: (
        list(LOCAL_GROUP_TITLES).index(group) if group in LOCAL_GROUP_TITLES else 999, group
    ))
    for group in ordered_groups:
        title = LOCAL_GROUP_TITLES.get(group, group.replace('-', ' ').capitalize())
        cards = []
        for item in manifest['images']:
            item_group = item.get('local_group', Path(item['local_file']).parent.name)
            if item_group != group or item['download_status'] == 'error':
                continue
            path = esc(item['local_file'], quote=True)
            cards.append(
                '<figure><a href="' + path + '"><img loading="lazy" src="' + path +
                '" alt="' + esc(item['legacy_label'], quote=True) + '"></a><figcaption><b>' +
                esc(item['legacy_label']) + '</b> · ID ' + str(item['source_attachment_id']) +
                '<br>' + str(item['width']) + ' × ' + str(item['height']) +
                '<br><small>' + esc(Path(item['local_file']).name) + '</small></figcaption></figure>'
            )
        sections.append('<h2>' + esc(title) + ' (' + str(len(cards)) + ')</h2><div class="grid">' + ''.join(cards) + '</div>')
    # This is an offline inspection artifact, not a WordPress page or theme style.
    markup = '''<!doctype html><html lang="lt"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Juvelyrinis darbas – originalios nuotraukos</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:1500px;margin:32px auto;padding:0 24px;background:#f5f5f5;color:#222}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}figure{margin:0;background:white;padding:12px;border:1px solid #ddd;border-radius:8px}img{width:100%;height:210px;object-fit:contain}figcaption{padding-top:8px}small{overflow-wrap:anywhere;color:#666}</style>
<h1>Juvelyrinis darbas – originalios nuotraukos</h1>
<p>Nuotraukos nepakeistos. Išsaugoti senojo puslapio numeriai ir vaizdų ID.
Pasikartojantys numeriai automatiškai nesujungti. Grupės atitinka vietinius aplankus,
o pirminės svetainės skyriai išsaugoti JSON registre.
Spustelėkite nuotrauką, kad atvertumėte originalą.</p>'''
    (DEST / 'index.html').write_text(markup + ''.join(sections) + '</html>', encoding='utf-8')


def main():
    arguments = argparse.ArgumentParser(description=__doc__)
    arguments.add_argument('--sync-local', action='store_true',
                           help='Update manifest paths and gallery after local moves; no network/downloads')
    args = arguments.parse_args()
    manifest_path = DEST / 'manifest.json'
    previous_manifest = {}
    if manifest_path.exists():
        previous_manifest = reconcile_local_files(
            json.loads(manifest_path.read_text()), allow_missing=not args.sync_local
        )
    if args.sync_local:
        if not previous_manifest:
            raise RuntimeError('No existing manifest to synchronize')
        save_manifest(previous_manifest)
        write_index(previous_manifest)
        print(json.dumps({'synchronized': len(previous_manifest['images']),
                          'folders': previous_manifest['local_folder_counts'],
                          'unmapped': previous_manifest['unmapped_local_files']}, ensure_ascii=False))
        return
    previous = {item['source_key']: item for item in previous_manifest.get('images', [])}
    # Finish all source discovery before creating or changing the local archive.
    selected = []
    section_counts = {}
    for slug, title, url in SECTIONS:
        parser = GalleryParser()
        parser.feed(fetch(url).decode('utf-8'))
        if not parser.items:
            raise RuntimeError('No gallery photos found on ' + url)
        section_counts[slug] = len(parser.items)
        for position, item in enumerate(parser.items, 1):
            selected.append(dict(item, source_section=slug, source_section_title=title,
                                 source_page=url, source_position=position))
    ids = sorted({item['source_attachment_id'] for item in selected})
    query = urlencode({'include': ','.join(map(str, ids)), 'per_page': 100,
                       '_fields': 'id,source_url,media_details,title,caption,alt_text'})
    media = json.loads(fetch(ORIGIN + '/wp-json/wp/v2/media?' + query))
    by_id = {item['id']: item for item in media}
    if set(by_id) != set(ids):
        raise RuntimeError('Media API did not return all selected image IDs')

    records = []
    for item in selected:
        attachment = by_id[item['source_attachment_id']]
        label = item['legacy_label']
        number = re.sub(r'^Nr\.\s*', '', label, flags=re.I).strip()
        key = re.sub(r'[^a-z0-9]+', '-', number.lower()).strip('-')
        if not key:
            raise RuntimeError('Missing legacy label for ' + str(item['source_attachment_id']))
        filename = 'juvelyrinis-darbas-' + item['source_section'] + '-nr-' + key + '-src-' + str(attachment['id']) + '.jpg'
        details = attachment['media_details']
        original_url = attachment['source_url']
        # Modern WordPress may expose a pre-scaled original in original_image.
        if details.get('original_image'):
            raise RuntimeError('Pre-scaled original requires explicit source review: ' + str(attachment['id']))
        source_key = 'juvelyrinisdarbas.lt:attachment:' + str(attachment['id'])
        old = previous.get(source_key, {})
        default_path = 'originals/' + item['source_section'] + '/' + filename
        records.append(dict(item,
            source_key=source_key,
            legacy_number=number,
            source_title=plain_text(attachment['title']['rendered']),
            source_caption=plain_text(attachment['caption']['rendered']),
            original_alt=attachment['alt_text'],
            original_url=original_url,
            source_filename=Path(urlparse(original_url).path).name,
            source_width=details['width'], source_height=details['height'],
            local_file=old.get('local_file', default_path),
            local_group=old.get('local_group', item['source_section']),
            proposed_title_lt=old.get('proposed_title_lt', ('Žiedai Nr. ' if item['source_section'] == 'ziedai' else 'Juvelyrinis gaminys Nr. ') + number),
            title_status=old.get('title_status', 'neutral_proposal_not_owner_approved'),
            processing_status=old.get('processing_status', 'original_unedited'),
        ))

    DEST.mkdir(parents=True, exist_ok=True)
    results = {}
    with ThreadPoolExecutor(max_workers=4) as pool:
        jobs = {pool.submit(collect_item, item, previous): i for i, item in enumerate(records)}
        for completed, job in enumerate(as_completed(jobs), 1):
            results[jobs[job]] = job.result()
            if completed % 8 == 0 or completed == len(records):
                print('Verified photos: ' + str(completed) + '/' + str(len(records)), flush=True)
    images = [results[i] for i in range(len(records))]
    duplicate_labels = {}
    for slug, _, _ in SECTIONS:
        duplicate_labels[slug] = {label: count for label, count in Counter(
            i['legacy_label'] for i in images if i['source_section'] == slug
        ).items() if count > 1}
    manifest = dict(previous_manifest, **{
        'collected_at_utc': previous_manifest.get('collected_at_utc', datetime.now(timezone.utc).isoformat()),
        'last_collection_run_at_utc': datetime.now(timezone.utc).isoformat(),
        'scope': 'Gallery images from the two selected source pages, not the whole media library.',
        'originals_modified': False,
        'section_counts': section_counts,
        'duplicate_labels_within_sections': duplicate_labels,
        'images': images,
        'errors': [i['source_key'] for i in images if i['download_status'] == 'error'],
    })
    manifest = reconcile_local_files(manifest, allow_missing=True)
    save_manifest(manifest)
    write_index(manifest)
    print(json.dumps({'total': len(images), 'errors': manifest['errors'],
                      'bytes': sum(i.get('bytes', 0) for i in images)}, ensure_ascii=False), flush=True)
    if manifest['errors']:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
