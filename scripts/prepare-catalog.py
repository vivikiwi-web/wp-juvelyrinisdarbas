#!/usr/bin/env python3
"""Validate the curated catalog and prepare WebP/review HTML without touching WP."""
import argparse
import hashlib
import html
import json
import os
from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / 'project-files/catalog-import/products.json'


def local_path(value):
    path = (ROOT / value).resolve()
    if not path.is_relative_to(ROOT):
        raise ValueError('Path escapes theme directory: ' + value)
    return path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--build', action='store_true', help='Write derived WebP and review HTML')
    parser.add_argument('--quality', type=int, default=80)
    args = parser.parse_args()
    if not 1 <= args.quality <= 100:
        parser.error('Quality must be 1–100')
    catalog = json.loads(MANIFEST.read_text())
    products = catalog['products']
    for field in ('id', 'sku', 'slug'):
        if len({p[field] for p in products}) != len(products):
            raise ValueError('Duplicate ' + field)
    for product in products:
        if product['type'] != 'simple':
            raise ValueError('Only simple catalog models are allowed')
        if product['price_is_example'] and product['status'] != 'draft':
            raise ValueError('Example prices must remain draft-only')
        for asset in product['images']:
            source = local_path(asset['source'])
            if hashlib.sha256(source.read_bytes()).hexdigest() != asset['source_sha256']:
                raise ValueError('Source changed; review manifest first: ' + str(source))
            output = local_path(asset['webp'])
            if args.build:
                if output.exists() and asset.get('webp_sha256') != hashlib.sha256(output.read_bytes()).hexdigest():
                    raise ValueError('Unrecognized derived file; not overwritten: ' + str(output))
                output.parent.mkdir(parents=True, exist_ok=True)
                temporary = output.with_suffix('.tmp.webp')
                with Image.open(source) as original:
                    image = ImageOps.exif_transpose(original)
                    options = {'format': 'WEBP', 'quality': args.quality, 'method': 6}
                    if original.info.get('icc_profile'):
                        options['icc_profile'] = original.info['icc_profile']
                    image.save(temporary, **options)
                    dimensions = image.size
                with Image.open(temporary) as check:
                    check.load()
                    assert check.size == dimensions
                temporary.replace(output)
                asset.update(webp_sha256=hashlib.sha256(output.read_bytes()).hexdigest(),
                             width=dimensions[0], height=dimensions[1],
                             quality=args.quality, webp_bytes=output.stat().st_size)
    if args.build:
        temporary = MANIFEST.with_suffix('.json.tmp')
        temporary.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + '\n')
        temporary.replace(MANIFEST)
        write_review(catalog)
    print(json.dumps({'products': len(products), 'priced_products': sum(p['regular_price'] is not None for p in products),
                      'webp_bytes': sum(a.get('webp_bytes', 0) for p in products for a in p['images']),
                      'mode': 'build' if args.build else 'validation_only'}, ensure_ascii=False))


def write_review(catalog):
    escape = html.escape
    cards = []
    for product in catalog['products']:
        source = os.path.relpath(local_path(product['images'][0]['webp']), MANIFEST.parent)
        price = ('Kaina: ' + product['regular_price'] + ' EUR'
                 if product['regular_price'] is not None else 'Kaina nenurodyta')
        cards.append('<article><img loading="lazy" src="' + escape(source, quote=True) +
                     '" alt="' + escape(product['title_lt'], quote=True) + '"><h2>' +
                     escape(product['title_lt']) + '</h2><p>' + escape(product['sku']) + ' · ' +
                     escape(product['category']) + '</p><p>' + escape(price) + '</p><p>' +
                     escape(product['short_description_lt']) + '</p>' +
                     ('<p class="review">Reikia patikrinti galimus modelio dublikatus.</p>' if product['review_notes'] else '') + '</article>')
    markup = '''<!doctype html><html lang="lt"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Juvelyrinis darbas – katalogo peržiūra</title><style>body{font:16px/1.5 system-ui,sans-serif;color:#282828;background:#f5f5f5;margin:32px auto;max-width:1500px;padding:0 24px}main{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px}article{background:white;border-radius:12px;padding:16px}img{width:100%;height:300px;object-fit:contain}h2{font-size:19px}.review{color:#875b15}</style>
<h1>Juvelyrinis darbas – katalogo peržiūra</h1><p>64 paprastų gaminių modelių kandidatai. Prieš publikavimą reikia patvirtinti pavadinimus ir duomenis. Likučiai ir techninės savybės nepatvirtinti.</p><main>'''
    (MANIFEST.parent / 'review.html').write_text(markup + ''.join(cards) + '</main></html>')


if __name__ == '__main__':
    main()
