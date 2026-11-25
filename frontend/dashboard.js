(() => {
  const groups = [...document.querySelectorAll('[data-info-group]')];
  document.querySelector('[data-expand-groups]')?.addEventListener('click', () => groups.forEach(group => { group.open = true; }));
  document.querySelector('[data-collapse-groups]')?.addEventListener('click', () => groups.forEach(group => { group.open = false; }));
  function revealHash() {
    if (!window.location.hash) return;
    let id;
    try { id = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
    const target = document.getElementById(id);
    if (target?.matches('[data-info-group]')) target.open = true;
  }
  window.addEventListener('hashchange', revealHash);
  revealHash();
  const collator = new Intl.Collator('da', {numeric: true, sensitivity: 'base'});
  for (const list of document.querySelectorAll('[data-data-list]')) {
    const search = list.querySelector('[data-list-search]');
    const filter = list.querySelector('[data-list-filter]');
    const sort = list.querySelector('[data-list-sort]');
    const direction = list.querySelector('[data-list-direction]');
    const tbody = list.querySelector('tbody');
    const records = [...list.querySelectorAll('[data-list-row]')].map((row, index) => ({
      row, index,
      text: row.textContent.toLocaleLowerCase('da'),
      values: new Map([...row.querySelectorAll('[data-key]')].map(cell => [cell.dataset.key, cell.dataset.value]))
    }));
    const headings = [...list.querySelectorAll('th')];
    function update() {
      const words = search.value.trim().toLocaleLowerCase('da').split(/\s+/).filter(Boolean);
      const filteredValue = filter?.value ?? '';
      const key = sort.value;
      const ascending = direction.value === 'asc';
      const ordered = [...records].sort((a, b) => {
        const result = collator.compare(a.values.get(key) ?? '', b.values.get(key) ?? '');
        return (ascending ? result : -result) || a.index - b.index;
      });
      let visible = 0;
      const fragment = document.createDocumentFragment();
      for (const record of ordered) {
        record.row.hidden = !words.every(word => record.text.includes(word)) ||
          (filteredValue !== '' && record.values.get(filter.dataset.filterKey) !== filteredValue);
        if (!record.row.hidden) visible++;
        fragment.appendChild(record.row);
      }
      tbody.appendChild(fragment);
      list.querySelector('[data-list-count]').textContent = `${visible} af ${records.length} poster`;
      list.querySelector('[data-list-empty]').hidden = visible !== 0;
      for (const heading of headings) {
        const button = heading.querySelector('[data-sort-column]');
        const active = button?.dataset.sortColumn === key;
        heading.setAttribute('aria-sort', active ? (ascending ? 'ascending' : 'descending') : 'none');
      }
    }
    search.addEventListener('input', update);
    filter?.addEventListener('change', update);
    sort.addEventListener('change', update);
    direction.addEventListener('change', update);
    for (const button of list.querySelectorAll('[data-sort-column]')) {
      button.addEventListener('click', () => {
        const key = button.dataset.sortColumn;
        direction.value = sort.value === key && direction.value === 'asc' ? 'desc' : 'asc';
        sort.value = key;
        update();
      });
    }
    list.querySelector('[data-list-reset]').addEventListener('click', () => {
      search.value = '';
      if (filter) filter.value = '';
      sort.selectedIndex = 0;
      direction.value = 'asc';
      update();
    });
    update();
  }
})();
