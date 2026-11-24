(() => {
  'use strict';
  const editor = document.querySelector('.jd-fields-editor');
  if (!editor) return;
  const rows = editor.querySelector('.jd-fields-rows');
  let index = rows.children.length;
  const refreshConditions = () => {
    const definitions = [...rows.children].map(row => ({
      row,
      key: row.querySelector('input[name$="[key]"]').value,
      label: row.querySelector('input[name$="[label]"]').value || 'Naujas laukas',
      type: row.querySelector('.jd-field-type').value,
      options: row.querySelector('textarea[name$="[choices]"]').value.split(/\r?\n/).filter(line => line.trim()).map(line => {
        const separator = line.indexOf('|');
        return separator < 0 ? [line.trim(), line.trim()] : [line.slice(0, separator).trim(), line.slice(separator + 1).trim()];
      })
    }));
    const replaceOptions = (select, options, emptyLabel) => {
      const previous = select.value;
      select.replaceChildren(new Option(emptyLabel, ''), ...options.map(([value, label]) => new Option(label, value)));
      if (previous && !options.some(([value]) => value === previous)) select.add(new Option(`Pašalinta: ${previous}`, previous));
      select.value = previous;
    };
    definitions.forEach(field => {
      const source = field.row.querySelector('.jd-condition-source');
      replaceOptions(source, definitions.filter(other => other.key !== field.key && other.type === 'select').map(other => [other.key, other.label]), 'Visada aktyvus');
      const parent = definitions.find(other => other.key === source.value);
      replaceOptions(field.row.querySelector('.jd-condition-value'), parent?.options || [], 'Aptarti su juvelyru');
      field.row.querySelector('.jd-condition-details').hidden = !source.value;
    });
  };
  const update = row => {
    const type = row.querySelector('.jd-field-type').value;
    row.querySelector('.jd-field-choices').hidden = type !== 'select';
    row.querySelector('.jd-field-limits').hidden = type !== 'number';
  };
  editor.addEventListener('change', event => {
    const row = event.target.closest('.jd-field-row');
    if (!row) return;
    if (event.target.matches('.jd-condition-source')) row.querySelector('.jd-condition-value').value = '';
    update(row); refreshConditions();
  });
  editor.addEventListener('click', event => {
    const row = event.target.closest('.jd-field-row');
    if (event.target.closest('.jd-field-delete')) { row.remove(); refreshConditions(); }
    if (event.target.closest('.jd-field-add')) {
      if (rows.children.length >= 20) return;
      const key = `field_${Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('')}`;
      const html = editor.querySelector('template').innerHTML.replaceAll('__INDEX__', String(index++)).replaceAll('__KEY__', key);
      rows.insertAdjacentHTML('beforeend', html);
      refreshConditions();
      rows.lastElementChild.querySelector('input[type="text"]').focus();
    }
  });
  rows.querySelectorAll('.jd-field-row').forEach(update);
  refreshConditions();
})();
