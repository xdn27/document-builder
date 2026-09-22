/**
 * Memecah blok menjadi fragmen yang boleh berdiri sendiri di sebuah halaman,
 * lalu menyusunnya kembali menjadi blok utuh di setiap halaman.
 *
 * Hanya tabel dan daftar yang dipecah. Paragraf pindah utuh — lihat catatan
 * batasan pada rencana implementasi.
 */

/** @returns {'table'|'list'|null} */
export function groupKindFor(classList, breakInside) {
    if (breakInside === 'avoid') return null;

    const classes = Array.from(classList);

    if (classes.includes('db-table')) return 'table';
    if (classes.includes('db-list')) return 'list';

    return null;
}

export function containerNeedsHeader(descriptor, isFirstContainerOfGroup) {
    if (descriptor.repeatHeader === undefined) return false;

    return descriptor.repeatHeader === true || isFirstContainerOfGroup;
}

/**
 * @param {Element} blockEl
 * @returns {{el: Element, groupId: string|null, groupKind: 'table'|'list'|null, repeatHeader: boolean|undefined, template: Element|null}[]}
 */
export function describeFragments(blockEl) {
    const whole = [{
        el: blockEl,
        groupId: null,
        groupKind: null,
        repeatHeader: undefined,
        template: null,
    }];

    const kind = groupKindFor(blockEl.classList, blockEl.dataset.breakInside);

    if (kind === null) return whole;

    const groupId = blockEl.dataset.blockId || `grup-${Math.random().toString(36).slice(2)}`;

    if (kind === 'table') {
        const table = blockEl.querySelector('.db-table__table');
        const rows = table ? Array.from(table.querySelectorAll('.db-table__body > tr')) : [];

        if (rows.length === 0) return whole;

        // Kerangka: blok beserta tabelnya, tetapi tanpa isi baris.
        const template = blockEl.cloneNode(true);
        template.querySelector('.db-table__body').innerHTML = '';

        const repeatHeader = table.dataset.repeatHeader === '1';

        return rows.map((row) => ({
            el: row,
            groupId,
            groupKind: 'table',
            repeatHeader,
            template,
        }));
    }

    const items = Array.from(blockEl.querySelectorAll('.db-list__item'));

    if (items.length === 0) return whole;

    const template = blockEl.cloneNode(false);

    return items.map((item) => ({
        el: item,
        groupId,
        groupKind: 'list',
        repeatHeader: undefined,
        template,
    }));
}

export function openGroupContainer(descriptor, isFirstContainerOfGroup) {
    const container = descriptor.template.cloneNode(true);

    if (descriptor.groupKind === 'table' && !containerNeedsHeader(descriptor, isFirstContainerOfGroup)) {
        const head = container.querySelector('.db-table__head');

        if (head) head.remove();
    }

    return container;
}

export function appendToGroup(container, descriptor) {
    if (descriptor.groupKind === 'table') {
        container.querySelector('.db-table__body').appendChild(descriptor.el);

        return;
    }

    container.appendChild(descriptor.el);
}
