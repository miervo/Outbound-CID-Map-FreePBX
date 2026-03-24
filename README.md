# Outbound-CID-Map-FreePBX
Модуль подставляет конкретный внешний CallerID (`CALLERID(num)`) для каждого внутреннего номера при исходящих звонках на внешние номера (длина > 6 цифр).


# outcidmap — Модуль FreePBX 16: Outbound CID Map

Модуль подставляет конкретный внешний CallerID (`CALLERID(num)`) для каждого внутреннего номера при исходящих звонках на внешние номера (длина > 6 цифр).

---

## Возможности

- **Группы CallerID** — один внешний номер назначается группе, в которую входят несколько внутренних номеров
- **Маппинг 1:1** — каждому внутреннему номеру можно назначить свою группу
- **Автогенерация dialplan** — при нажатии «Apply Config» модуль сам пишет `macro-dialout-trunk-predial-hook`
- **Веб-интерфейс** — управление через стандартный интерфейс FreePBX (вкладка Admin)
- **Предпросмотр dialplan** — вкладка «Dialplan (preview)» показывает что будет записано в конфиг
- **Срабатывает только на внешние номера** — звонки на номера ≤ 6 цифр не затрагиваются

---

## Установка

### Способ 1: Через веб-интерфейс (рекомендуется)

1. Перейдите в **Admin → Module Admin → Upload Module**
2. Загрузите файл `outcidmap.tar.gz`
3. Нажмите **Process** → **Confirm**
4. Модуль появится в меню **Admin → Outbound CID Map**

### Способ 2: Через командную строку

```bash
# Копируем модуль
cp outcidmap.tar.gz /var/www/html/admin/modules/
cd /var/www/html/admin/modules/
tar xzf outcidmap.tar.gz

# Устанавливаем через fwconsole
fwconsole ma install outcidmap
fwconsole reload
```

---

## Использование

### 1. Создайте группы CallerID

Перейдите в **Admin → Outbound CID Map → Группы CallerID**

| Поле | Описание |
|------|----------|
| Название группы | Произвольное имя, например «Приёмная 524» |
| CallerID | Внешний номер, только цифры, 6–15 символов, например `74951234567` |
| Описание | Опциональный комментарий |

### 2. Назначьте номера на группы

Перейдите в **Admin → Outbound CID Map → Маппинги номеров**

| Поле | Описание |
|------|----------|
| Внутренний номер | Выберите из списка или введите вручную (например `2002`) |
| Группа CallerID | Выберите группу из созданных на шаге 1 |
| Описание | ФИО, должность — для удобства |

### 3. Нажмите «Apply Config»

После сохранения маппингов нажмите оранжевую кнопку **Apply Config** в верхней части FreePBX.

---

## Как работает dialplan

Модуль генерирует контекст `[macro-dialout-trunk-predial-hook]`:

```ini
[macro-dialout-trunk-predial-hook]
exten => s,1,NoOp(=== outcidmap: dynamic OUTBOUND CID ===)
 same => n,Set(OCMAP_SRC=${FILTER(0-9,${CUT(CUT(CHANNEL,/,2),-,1)})})
 same => n,Set(OCMAP_IS_EXT=${IF($[${LEN(${OUTNUM})}>6]?1:0)})
 same => n,GotoIf($["${OCMAP_IS_EXT}"="0"]?ocmap_end)
 same => n,Set(OCMAP_CID=)
 same => n,ExecIf($["${OCMAP_SRC}"="2002"]?Set(OCMAP_CID=74951234567))  ; Иванов И.И.
 same => n,ExecIf($["${OCMAP_SRC}"="2003"]?Set(OCMAP_CID=74951234567))  ; Петров П.П.
 same => n,GotoIf($["${OCMAP_CID}"=""]?ocmap_end)
 same => n,Set(CALLERID(num)=${OCMAP_CID})
 same => n,Set(CALLERID(name)=${OCMAP_CID})
 same => n,Set(PJSIP_HEADER(add,P-Asserted-Identity)=<sip:${OCMAP_CID}@${SIPDOMAIN}>)
 same => n(ocmap_end),MacroExit()
```

**Логика:**
1. Из имени канала извлекается внутренний номер (`OCMAP_SRC`)
2. Проверяется длина набранного номера — если ≤ 6 цифр, ничего не меняется (внутренний звонок)
3. Ищется маппинг для данного `OCMAP_SRC`
4. Если маппинг найден — подменяется `CALLERID(num)`, `CALLERID(name)` и P-Asserted-Identity заголовок

---

## Совместимость с существующим extensions_custom.conf

Если у вас уже есть `[macro-dialout-trunk-predial-hook]` в `extensions_custom.conf` — **удалите его**, иначе Asterisk выдаст предупреждение о дублировании приоритетов:

```
WARNING: Unable to register extension 's' priority 1 in 'macro-dialout-trunk-predial-hook', already in use
```

Модуль полностью заменяет ручной вариант из `extensions_custom.conf`.

---

## Структура файлов модуля

```
outcidmap/
├── module.xml              # Метаданные модуля
├── Outcidmap.class.php     # BMO класс: CRUD, генерация dialplan
├── page.outcidmap.php      # Веб-интерфейс (страница управления)
├── functions.inc.php       # Compatibility layer для FreePBX hooks
├── install.php             # Создание таблиц БД при установке
├── uninstall.php           # Удаление (таблицы сохраняются)
├── assets/
│   ├── css/outcidmap.css   # Стили
│   └── js/outcidmap.js     # JavaScript
└── README.md
```

---

## Таблицы БД

**`outcidmap_groups`** — группы CallerID:

| Поле | Тип | Описание |
|------|-----|----------|
| id | INT | Первичный ключ |
| name | VARCHAR(64) | Уникальное название группы |
| callerid | VARCHAR(32) | Внешний номер (только цифры) |
| description | VARCHAR(255) | Описание |

**`outcidmap_mapping`** — маппинги номеров:

| Поле | Тип | Описание |
|------|-----|----------|
| id | INT | Первичный ключ |
| extension | VARCHAR(32) | Внутренний номер (уникальный) |
| group_id | INT | Ссылка на outcidmap_groups.id |
| description | VARCHAR(255) | ФИО/должность |

---

## Требования

- FreePBX 16.x
- Asterisk 18.x
- PHP 7.4+ (поставляется с FreePBX 16)
- MySQL/MariaDB (FreePBX database)
