(() => {
  const collator = new Intl.Collator('da', {numeric: true, sensitivity: 'base'});
  for (const list of document.querySelectorAll('[data-software-list]')) {
    const rows = [...list.querySelectorAll('[data-software-row]')];
    const search = list.querySelector('[data-software-search]');
    const filters = [...list.querySelectorAll('[data-software-filter]')];
    const grouping = list.querySelector('[data-software-group]');
    const tbody = list.querySelector('tbody');
    const headings = [...list.querySelectorAll('[data-software-heading]')];
    const texts = new Map(rows.map(row => [row, row.textContent.toLocaleLowerCase('da')]));
    function update() {
      const words = search.value.trim().toLocaleLowerCase('da').split(/\s+/).filter(Boolean);
      const visibleCategories = new Set();
      let visible = 0;
      for (const row of rows) {
        row.hidden = !words.every(word => texts.get(row).includes(word)) || filters.some(filter => filter.value && row.dataset[filter.dataset.softwareFilter] !== filter.value);
        if (!row.hidden) { visible++; visibleCategories.add(row.dataset.category); }
      }
      for (const heading of headings) heading.hidden = grouping.value !== 'category' || !visibleCategories.has(heading.dataset.softwareHeading);
      const ordered = [...rows].sort((a, b) => (grouping.value === 'category' ? collator.compare(a.cells[3].textContent, b.cells[3].textContent) : 0) || collator.compare(a.cells[0].querySelector('strong').textContent, b.cells[0].querySelector('strong').textContent));
      if (grouping.value === 'category') {
        for (const heading of headings) {
          tbody.appendChild(heading);
          for (const row of ordered) if (row.dataset.category === heading.dataset.softwareHeading) tbody.appendChild(row);
        }
      } else for (const row of ordered) tbody.appendChild(row);
      list.querySelector('[data-software-count]').textContent = `${visible} af ${rows.length} poster`;
      list.querySelector('[data-software-empty]').hidden = visible !== 0;
    }
    search.addEventListener('input', update);
    filters.forEach(filter => filter.addEventListener('change', update));
    grouping.addEventListener('change', update);
    list.querySelector('[data-software-reset]').addEventListener('click', () => { search.value = ''; filters.forEach(filter => { filter.value = ''; }); grouping.value = 'category'; update(); });
    update();
  }
  const category = document.querySelector('[data-software-edit-category]');
  const subcategory = document.querySelector('[data-software-edit-subcategory]');
  if (category && subcategory) {
    category.addEventListener('change', () => {
      for (const option of subcategory.options) option.hidden = option.value !== '' && option.dataset.category !== category.value;
      if (subcategory.selectedOptions[0]?.hidden) subcategory.value = '';
    });
    category.dispatchEvent(new Event('change'));
  }
  const refresh = document.querySelector('[data-software-refresh]');
  refresh?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = refresh.querySelector('button');
    const status = document.querySelector('[data-software-refresh-status]');
    button.disabled = true;
    let updated = 0;
    try {
      for (let batch = 0; batch < 30; batch++) {
        status.textContent = `Henter offentlige metadata… ${updated} produkter opdateret.`;
        const response = await fetch(refresh.action, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'}, body: new FormData(refresh)});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Opdateringen mislykkedes. Prøv igen senere.');
        updated += result.updated.length;
        if (result.failed.length) throw new Error(`Kilder kunne ikke hentes for ${result.failed.join(', ')}. Eksisterende metadata er bevaret.`);
        if (result.remaining === 0) { status.textContent = `${updated} produkter opdateret. Kataloget er ajour. Genindlæs siden for at se ændringerne.`; break; }
      }
    } catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
  });
})();
