/* outcidmap module JS */
$(document).ready(function () {

    // Активируем нужную вкладку при загрузке
    var urlParams = new URLSearchParams(window.location.search);
    var section   = urlParams.get('section') || 'mappings';

    // Переключение вкладок через URL
    $('#outcidmap-tabs a').on('click', function (e) {
        e.preventDefault();
        $(this).tab('show');
        var tabId = $(this).attr('href').replace('#tab-', '');
        var newUrl = window.location.pathname +
            '?display=outcidmap&section=' + tabId;
        window.history.pushState({}, '', newUrl);
    });

    // Активируем нужную вкладку
    var tabMap = {
        'mappings': '#tab-mappings',
        'groups':   '#tab-groups',
        'dialplan': '#tab-dialplan'
    };
    if (tabMap[section]) {
        $('#outcidmap-tabs a[href="' + tabMap[section] + '"]').tab('show');
    }

    // Валидация CallerID: только цифры при вводе
    $('#group_callerid').on('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });

    // Highlight строки при наведении для таблицы маппингов
    $('#tbl-mappings tbody tr').hover(
        function () { $(this).addClass('active'); },
        function () { $(this).removeClass('active'); }
    );

    // DataTables если доступен
    if ($.fn.DataTable && $('#tbl-mappings').length) {
        $('#tbl-mappings').DataTable({
            "paging":   false,
            "info":     false,
            "language": {
                "search":  "Поиск:",
                "zeroRecords": "Ничего не найдено"
            },
            "columnDefs": [
                { "orderable": false, "targets": -1 }
            ]
        });
    }
});
