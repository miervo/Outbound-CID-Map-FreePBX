/* outcidmap module JS — v1.0.1 */
$(document).ready(function () {

    // =========================================================
    // Вкладки
    // =========================================================
    var urlParams = new URLSearchParams(window.location.search);
    var section   = urlParams.get('section') || 'mappings';

    $('#outcidmap-tabs a').on('click', function (e) {
        e.preventDefault();
        $(this).tab('show');
        var tabId = $(this).attr('href').replace('#tab-', '');
        window.history.pushState({}, '', window.location.pathname + '?display=outcidmap&section=' + tabId);
    });

    var tabMap = { 'mappings': '#tab-mappings', 'groups': '#tab-groups', 'dialplan': '#tab-dialplan' };
    if (tabMap[section]) {
        $('#outcidmap-tabs a[href="' + tabMap[section] + '"]').tab('show');
    }

    // =========================================================
    // Валидация CallerID
    // =========================================================
    $('#group_callerid').on('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });

    // =========================================================
    // Сортируемые таблицы
    // =========================================================

    /**
     * Инициализирует кликабельные заголовки для сортировки таблицы.
     * @param {string} tableId  — id таблицы (без #)
     */
    function initSortable(tableId) {
        var $table = $('#' + tableId);
        if (!$table.length) return;

        // Состояние: { colIndex: int, dir: 'asc'|'desc' }
        var sortState = { col: -1, dir: 'asc' };

        $table.find('thead th.sortable').each(function (i) {
            var $th = $(this);
            var colIdx = $th.data('col');

            $th.css('cursor', 'pointer').append(
                '<span class="ocm-sort-icon"> <i class="fa fa-sort"></i></span>'
            );

            $th.on('click', function () {
                var newDir = (sortState.col === colIdx && sortState.dir === 'asc') ? 'desc' : 'asc';
                sortState = { col: colIdx, dir: newDir };

                // Сбрасываем иконки
                $table.find('thead th.sortable .ocm-sort-icon i')
                    .attr('class', 'fa fa-sort');

                // Устанавливаем иконку на текущий столбец
                $th.find('.ocm-sort-icon i')
                    .attr('class', newDir === 'asc' ? 'fa fa-sort-asc' : 'fa fa-sort-desc');

                sortTable($table, colIdx, newDir);
            });
        });
    }

    function sortTable($table, colIdx, dir) {
        var $tbody = $table.find('tbody');
        var rows   = $tbody.find('tr').toArray();

        rows.sort(function (a, b) {
            var aText = $(a).find('td').eq(colIdx).text().trim().toLowerCase();
            var bText = $(b).find('td').eq(colIdx).text().trim().toLowerCase();

            // Числовое сравнение если оба — числа
            var aNum = parseFloat(aText);
            var bNum = parseFloat(bText);
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return dir === 'asc' ? aNum - bNum : bNum - aNum;
            }

            if (aText < bText) return dir === 'asc' ? -1 :  1;
            if (aText > bText) return dir === 'asc' ?  1 : -1;
            return 0;
        });

        $.each(rows, function (i, row) { $tbody.append(row); });
    }

    // =========================================================
    // Поиск по таблице
    // =========================================================

    /**
     * Привязывает поле поиска к таблице.
     * @param {string} inputId  — id поля ввода (без #)
     * @param {string} tableId  — id таблицы (без #)
     * @param {Array}  cols     — индексы столбцов по которым искать (все если не указано)
     */
    function initSearch(inputId, tableId, cols) {
        var $input = $('#' + inputId);
        var $table = $('#' + tableId);
        if (!$input.length || !$table.length) return;

        $input.on('input', function () {
            var query = $(this).val().trim().toLowerCase();
            var $rows = $table.find('tbody tr');

            $rows.each(function () {
                var $tds = $(this).find('td');
                var match = false;

                if (!query) {
                    match = true;
                } else {
                    var searchCols = cols || Array.from({length: $tds.length}, function(_, i){ return i; });
                    searchCols.forEach(function (ci) {
                        if ($tds.eq(ci).text().trim().toLowerCase().indexOf(query) !== -1) {
                            match = true;
                        }
                    });
                }

                $(this).toggle(match);
            });

            // Показываем/скрываем строку "ничего не найдено"
            var visible = $table.find('tbody tr:visible').length;
            var $empty  = $table.siblings('.ocm-no-results');
            if (!visible && query) {
                if (!$empty.length) {
                    $table.after('<div class="alert alert-warning ocm-no-results"><i class="fa fa-search"></i> Ничего не найдено</div>');
                }
            } else {
                $empty.remove();
            }
        });

        // Кнопка сброса
        $input.closest('.ocm-search-wrap').find('.ocm-search-clear').on('click', function () {
            $input.val('').trigger('input');
            $input.focus();
        });
    }

    // =========================================================
    // Инициализация: таблица маппингов
    // =========================================================
    initSortable('tbl-mappings');
    initSearch('search-mappings', 'tbl-mappings');

    // =========================================================
    // Инициализация: таблица групп
    // =========================================================
    initSortable('tbl-groups');
    initSearch('search-groups', 'tbl-groups');

});
