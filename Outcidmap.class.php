<?php
// FreePBX BMO Module: outcidmap
// Класс основной логики: CRUD, генерация dialplan

namespace FreePBX\modules;

class Outcidmap extends \FreePBX_Helpers implements \BMO {

    public function __construct($freepbx = null) {
        if ($freepbx === null) {
            throw new \Exception('Not given a FreePBX Object');
        }
        $this->FreePBX = $freepbx;
        $this->db       = $freepbx->Database;
    }

    // =========================================================
    // BMO Interface: вызывается при install/upgrade модуля
    // =========================================================
    public function install() {
        // Таблица групп CallerID
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outcidmap_groups` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(64)  NOT NULL COMMENT 'Название группы',
                `callerid`    VARCHAR(32)  NOT NULL COMMENT 'Внешний CallerID (только цифры)',
                `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Описание',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Таблица маппинга внутренний → группа
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outcidmap_mapping` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `extension`   VARCHAR(32)  NOT NULL COMMENT 'Внутренний номер',
                `group_id`    INT UNSIGNED NOT NULL COMMENT 'ID группы CallerID',
                `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Описание (ФИО, должность)',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_extension` (`extension`),
                KEY `fk_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function uninstall() {}
    public function backup()    {}
    public function restore($backup) {}
    public function doConfigPageInit($page) {}
    public function search($query, &$results) {}

    // =========================================================
    // BMO DialplanHook — регистрация в системе FreePBX Hooks
    // Фреймворк вызывает myDialplanHooks() чтобы узнать приоритет,
    // затем вызывает doDialplanHook() для заполнения dialplan
    // =========================================================

    // Возвращает приоритет вызова (500 = после core, до custom)
    public function myDialplanHooks() {
        return 500;
    }

    public function doDialplanHook(&$ext, $engine, $priority) {
        if ($engine !== 'asterisk') {
            return;
        }

        $mappings = $this->getMappingsWithCID();
        $context  = 'macro-dialout-trunk-predial-hook';

        if (empty($mappings)) {
            // Пустой макрос — если маппингов нет
            $ext->add($context, 's', '', new \ext_noop('outcidmap: no mappings defined'));
            $ext->add($context, 's', '', new \ext_macroexit(''));
            return;
        }

        // Основные шаги
        $ext->add($context, 's', '',         new \ext_noop('=== outcidmap: dynamic OUTBOUND CID ==='));
        $ext->add($context, 's', '',         new \ext_noop('CHANNEL=${CHANNEL}'));

        // Извлекаем внутренний номер из имени канала
        $ext->add($context, 's', '',         new \ext_setvar('OCMAP_SRC', '${FILTER(0-9,${CUT(CUT(CHANNEL,/,2),-,1)})}'));
        $ext->add($context, 's', '',         new \ext_noop('outcidmap src ext: ${OCMAP_SRC}'));

        // Проверка: внешний ли вызов (более 6 цифр)
        $ext->add($context, 's', '',         new \ext_setvar('OCMAP_IS_EXT', '${IF($[${LEN(${OUTNUM})}>6]?1:0)}'));
        $ext->add($context, 's', '',         new \ext_noop('outcidmap OUTNUM=${OUTNUM} IS_EXT=${OCMAP_IS_EXT}'));
        $ext->add($context, 's', '',         new \extension('GotoIf($["${OCMAP_IS_EXT}"="0"]?ocmap_end)'));

        // Сбрасываем CID
        $ext->add($context, 's', '',         new \ext_setvar('OCMAP_CID', ''));

        // ExecIf для каждого маппинга
        foreach ($mappings as $row) {
            $extNum = $this->_sanitizeExt($row['extension']);
            $cid    = $this->_sanitizeCID($row['callerid']);
            $desc   = !empty($row['description']) ? $row['description'] : $row['extension'];
            $ext->add($context, 's', '', new \extension(
                'ExecIf($["${OCMAP_SRC}"="' . $extNum . '"]?Set(OCMAP_CID=' . $cid . '))' .
                ' ; ' . $desc
            ));
        }

        $ext->add($context, 's', '',         new \ext_noop('outcidmap selected CID: ${OCMAP_CID}'));
        $ext->add($context, 's', '',         new \extension('GotoIf($["${OCMAP_CID}"=""]?ocmap_end)'));

        // Подмена CallerID
        $ext->add($context, 's', '',         new \extension('Set(CALLERID(num)=${OCMAP_CID})'));
        $ext->add($context, 's', '',         new \extension('Set(CALLERID(name)=${OCMAP_CID})'));
        $ext->add($context, 's', '',         new \extension('Set(PJSIP_HEADER(add,P-Asserted-Identity)=<sip:${OCMAP_CID}@${SIPDOMAIN}>)'));
        $ext->add($context, 's', '',         new \ext_noop('outcidmap applied CID ${OCMAP_CID} for ext ${OCMAP_SRC}'));

        // Метка выхода
        $ext->add($context, 's', 'ocmap_end', new \ext_macroexit(''));
    }

    // =========================================================
    // Генерация dialplan
    // ВАЖНО: метод называется generateDialplan(), а НЕ getConfig() —
    // getConfig() зарезервирован родительским классом DB_Helper
    // =========================================================
    public function generateDialplan($engine = 'asterisk') {
        if ($engine !== 'asterisk') return '';

        $mappings = $this->getMappingsWithCID();

        if (empty($mappings)) {
            // Пустой hook чтобы макрос был зарегистрирован
            $conf  = "[macro-dialout-trunk-predial-hook]\n";
            $conf .= "exten => s,1,NoOp(outcidmap: no mappings defined)\n";
            $conf .= " same => n,MacroExit()\n\n";
            return $conf;
        }

        $conf  = "; === outcidmap: Outbound CID Map ===\n";
        $conf .= "; Автоматически сгенерировано модулем outcidmap\n";
        $conf .= "; Не редактируйте вручную — изменения будут перезаписаны\n\n";
        $conf .= "[macro-dialout-trunk-predial-hook]\n";
        $conf .= "exten => s,1,NoOp(=== outcidmap: dynamic OUTBOUND CID ===)\n";
        $conf .= " same => n,NoOp(CHANNEL=\${CHANNEL})\n";

        // Извлекаем внутренний номер из канала
        $conf .= " same => n,Set(OCMAP_SRC=\${FILTER(0-9,\${CUT(CUT(CHANNEL,/,2),-,1)})})\n";
        $conf .= " same => n,NoOp(outcidmap src ext: \${OCMAP_SRC})\n\n";

        // Проверяем: внешний ли вызов (более 6 цифр)
        $conf .= " same => n,Set(OCMAP_IS_EXT=\${IF(\$[\${LEN(\${OUTNUM})}>6]?1:0)})\n";
        $conf .= " same => n,NoOp(outcidmap OUTNUM=\${OUTNUM} IS_EXT=\${OCMAP_IS_EXT})\n";
        $conf .= " same => n,GotoIf(\$[\"\${OCMAP_IS_EXT}\"=\"0\"]?ocmap_end)\n\n";

        // Сбрасываем переменную CID
        $conf .= " same => n,Set(OCMAP_CID=)\n\n";

        // Генерируем ExecIf для каждого маппинга
        foreach ($mappings as $row) {
            $ext = $this->_sanitizeExt($row['extension']);
            $cid = $this->_sanitizeCID($row['callerid']);
            $desc = !empty($row['description']) ? $row['description'] : $row['extension'];
            $conf .= " same => n,ExecIf(\$[\"\${OCMAP_SRC}\"=\"{$ext}\"]?Set(OCMAP_CID={$cid}))\t; {$desc}\n";
        }

        $conf .= "\n same => n,NoOp(outcidmap selected CID: \${OCMAP_CID})\n";
        $conf .= " same => n,GotoIf(\$[\"\${OCMAP_CID}\"=\"\"]?ocmap_end)\n\n";

        // Подмена CallerID
        $conf .= " same => n,Set(CALLERID(num)=\${OCMAP_CID})\n";
        $conf .= " same => n,Set(CALLERID(name)=\${OCMAP_CID})\n";
        $conf .= " same => n,Set(PJSIP_HEADER(add,P-Asserted-Identity)=<sip:\${OCMAP_CID}@\${SIPDOMAIN}>)\n";
        $conf .= " same => n,NoOp(outcidmap applied CID \${OCMAP_CID} for ext \${OCMAP_SRC})\n\n";

        $conf .= " same => n(ocmap_end),MacroExit()\n\n";

        return $conf;
    }

    // =========================================================
    // CRUD: Группы CallerID
    // =========================================================
    public function getGroups() {
        $stmt = $this->db->prepare(
            "SELECT id, name, callerid, description FROM outcidmap_groups ORDER BY name"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getGroup($id) {
        $stmt = $this->db->prepare(
            "SELECT id, name, callerid, description FROM outcidmap_groups WHERE id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function saveGroup($name, $callerid, $description = '', $id = null) {
        $name        = trim($name);
        $callerid    = preg_replace('/\D/', '', trim($callerid));
        $description = trim($description);

        if (empty($name) || empty($callerid)) {
            throw new \Exception('Название группы и CallerID обязательны');
        }
        if (!preg_match('/^\d{6,15}$/', $callerid)) {
            throw new \Exception('CallerID должен содержать от 6 до 15 цифр');
        }

        if ($id) {
            $stmt = $this->db->prepare(
                "UPDATE outcidmap_groups SET name=?, callerid=?, description=? WHERE id=?"
            );
            $stmt->execute([$name, $callerid, $description, $id]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO outcidmap_groups (name, callerid, description) VALUES (?,?,?)"
            );
            $stmt->execute([$name, $callerid, $description]);
        }
        return true;
    }

    public function deleteGroup($id) {
        // Снимаем маппинги с этой группой
        $stmt = $this->db->prepare("DELETE FROM outcidmap_mapping WHERE group_id = ?");
        $stmt->execute([$id]);

        $stmt = $this->db->prepare("DELETE FROM outcidmap_groups WHERE id = ?");
        $stmt->execute([$id]);
        return true;
    }

    // =========================================================
    // CRUD: Маппинги внутренних номеров
    // =========================================================
    public function getMappings() {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.extension, m.group_id, m.description,
                    g.name AS group_name, g.callerid
             FROM outcidmap_mapping m
             JOIN outcidmap_groups g ON g.id = m.group_id
             ORDER BY m.extension"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getMappingsWithCID() {
        return $this->getMappings();
    }

    public function getMapping($id) {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.extension, m.group_id, m.description
             FROM outcidmap_mapping m WHERE m.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function saveMapping($extension, $group_id, $description = '', $id = null) {
        $extension   = trim($extension);
        $group_id    = (int)$group_id;
        $description = trim($description);

        if (empty($extension)) {
            throw new \Exception('Внутренний номер обязателен');
        }
        if ($group_id <= 0) {
            throw new \Exception('Необходимо выбрать группу CallerID');
        }
        // Проверяем что группа существует
        $g = $this->getGroup($group_id);
        if (!$g) {
            throw new \Exception('Группа CallerID не найдена');
        }

        if ($id) {
            $stmt = $this->db->prepare(
                "UPDATE outcidmap_mapping SET extension=?, group_id=?, description=? WHERE id=?"
            );
            $stmt->execute([$extension, $group_id, $description, $id]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO outcidmap_mapping (extension, group_id, description) VALUES (?,?,?)"
            );
            $stmt->execute([$extension, $group_id, $description]);
        }
        return true;
    }

    public function deleteMapping($id) {
        $stmt = $this->db->prepare("DELETE FROM outcidmap_mapping WHERE id = ?");
        $stmt->execute([$id]);
        return true;
    }

    // =========================================================
    // Вспомогательные методы
    // =========================================================
    private function _sanitizeExt($ext) {
        return preg_replace('/[^0-9*#]/', '', $ext);
    }

    private function _sanitizeCID($cid) {
        return preg_replace('/\D/', '', $cid);
    }

    // Возвращает список внутренних номеров из FreePBX core (для select)
    public function getCoreExtensions() {
        try {
            $stmt = $this->db->prepare(
                "SELECT extension, name FROM users ORDER BY extension"
            );
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }
}
