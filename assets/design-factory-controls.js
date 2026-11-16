/* Local form controls only: all version, upload and geometry admission stays server-side. */
(() => {
  'use strict';
  document.querySelectorAll('[data-df-regions]').forEach(group => {
    const rows = group.querySelector('[data-df-region-rows]');
    const add = group.querySelector('[data-df-region-add]');
    const status = group.querySelector('[data-df-region-status]');
    const refresh = () => {
      [...rows.children].forEach((row, index) => {
        row.querySelector('[data-df-region-number]').textContent = String(index + 1);
        row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/native_regions\[[^\]]+\]/, `native_regions[${index}]`); });
        row.querySelector('[data-df-region-remove]').disabled = rows.children.length === 1;
      });
      add.disabled = rows.children.length >= 10;
      status.textContent = `${rows.children.length} of 10 reservation regions`;
    };
    add.addEventListener('click', () => {
      if (rows.children.length >= 10) return;
      const fragment = group.querySelector('template').content.cloneNode(true);
      if (group.closest('form').querySelector('[name="operation"]').value === 'design_prepare') {
        fragment.querySelectorAll('input').forEach(input => { input.required = true; });
      }
      rows.append(fragment); refresh(); rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', event => {
      const remove = event.target.closest('[data-df-region-remove]');
      if (!remove || rows.children.length <= 1) return;
      const row = remove.closest('[data-df-region-row]');
      const next = row.nextElementSibling || row.previousElementSibling;
      row.remove(); refresh(); next.querySelector('input').focus();
    });
    refresh();
  });
  document.querySelectorAll('form').forEach(form => {
    const svg = form.querySelector('[name="artwork_svg"]');
    const pngs = form.querySelector('[name="artwork_pngs[]"]');
    if (!svg || !pngs) return;
    const status = form.querySelector('[data-df-upload-status]');
    const validate = () => {
      let svgError = '', pngError = '';
      const source = svg.files[0];
      if (source && (!/\.svg$/i.test(source.name) || source.size > 60000 || source.size < 1)) svgError = 'Choose an SVG file of 1–60000 bytes.';
      if (pngs.files.length > 3) pngError = 'Choose up to three PNG files.';
      else if ([...pngs.files].some(file => !/\.png$/i.test(file.name) || file.size > 2097152 || file.size < 1)) pngError = 'Each PNG must be 1 byte–2 MiB.';
      svg.setCustomValidity(svgError); pngs.setCustomValidity(pngError);
      status.textContent = svgError || pngError || (source ? `${source.name}; ${pngs.files.length} supplemental PNG files. Server validation is required.` : '');
    };
    svg.addEventListener('change', validate); pngs.addEventListener('change', validate);
  });
})();
