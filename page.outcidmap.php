<?php
// FreePBX Module: outcidmap
// page.outcidmap.php — точка входа для веб-интерфейса

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Получаем экземпляр модуля
/** @var FreePBX\modules\Outcidmap $module */
$module = \FreePBX::Outcidmap();

$action  = isset($_REQUEST['action'])  ? $_REQUEST['action']  : 'list';
$section = isset($_REQUEST['section']) ? $_REQUEST['section'] : 'mappings';
$msg     = '';
$error   = '';

// =========================================================
// Обработка POST запросов
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($action) {
            // --- Группы ---
            case 'save_group':
                $module->saveGroup(
                    $_POST['group_name'],
                    $_POST['group_callerid'],
                    isset($_POST['group_desc']) ? $_POST['group_desc'] : '',
                    !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null
                );
                needreload();
                $msg = !empty($_POST['group_id'])
                    ? 'Группа обновлена. Нажмите "Apply Config" для применения.'
                    : 'Группа создана. Нажмите "Apply Config" для применения.';
                $section = 'groups';
                break;

            case 'delete_group':
                $module->deleteGroup((int)$_POST['group_id']);
                needreload();
                $msg     = 'Группа удалена. Нажмите "Apply Config" для применения.';
                $section = 'groups';
                break;

            // --- Маппинги ---
            case 'save_mapping':
                $module->saveMapping(
                    $_POST['mapping_ext'],
                    (int)$_POST['mapping_group'],
                    isset($_POST['mapping_desc']) ? $_POST['mapping_desc'] : '',
                    !empty($_POST['mapping_id']) ? (int)$_POST['mapping_id'] : null
                );
                needreload();
                $msg = !empty($_POST['mapping_id'])
                    ? 'Маппинг обновлён. Нажмите "Apply Config" для применения.'
                    : 'Маппинг добавлен. Нажмите "Apply Config" для применения.';
                $section = 'mappings';
                break;

            case 'delete_mapping':
                $module->deleteMapping((int)$_POST['mapping_id']);
                needreload();
                $msg     = 'Маппинг удалён. Нажмите "Apply Config" для применения.';
                $section = 'mappings';
                break;
        }
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

// =========================================================
// Загружаем данные для отображения
// =========================================================
$groups       = $module->getGroups();
$mappings     = $module->getMappings();
$coreExts     = $module->getCoreExtensions();

// Словарь extension => name для удобного отображения
$extNames = [];
foreach ($coreExts as $e) {
    $extNames[$e['extension']] = $e['name'];
}

// Редактирование
$editGroup   = null;
$editMapping = null;
if ($action === 'edit_group' && !empty($_REQUEST['id'])) {
    $editGroup = $module->getGroup((int)$_REQUEST['id']);
    $section   = 'groups';
}
if ($action === 'edit_mapping' && !empty($_REQUEST['id'])) {
    $editMapping = $module->getMapping((int)$_REQUEST['id']);
    $section     = 'mappings';
}
?>
<!-- Подключаем CSS модуля -->
<link rel="stylesheet" href="<?php echo \FreePBX::Config()->get('ADMIN_DIRECTORY') ?>/modules/outcidmap/assets/css/outcidmap.css">

<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">
      <h1><?php echo _('Outbound CID Map'); ?> <small><?php echo _('Подстановка CallerID при исходящих звонках'); ?></small></h1>

      <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible" role="alert">
          <button type="button" class="close" data-dismiss="alert">&times;</button>
          <?php echo htmlspecialchars($msg); ?>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible" role="alert">
          <button type="button" class="close" data-dismiss="alert">&times;</button>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <!-- Вкладки -->
      <ul class="nav nav-tabs" role="tablist" id="outcidmap-tabs">
        <li role="presentation" class="<?php echo $section === 'mappings' ? 'active' : ''; ?>">
          <a href="#tab-mappings" aria-controls="tab-mappings" role="tab" data-toggle="tab">
            <i class="fa fa-list"></i> <?php echo _('Маппинги номеров'); ?>
            <span class="badge"><?php echo count($mappings); ?></span>
          </a>
        </li>
        <li role="presentation" class="<?php echo $section === 'groups' ? 'active' : ''; ?>">
          <a href="#tab-groups" aria-controls="tab-groups" role="tab" data-toggle="tab">
            <i class="fa fa-tag"></i> <?php echo _('Группы CallerID'); ?>
            <span class="badge"><?php echo count($groups); ?></span>
          </a>
        </li>
        <li role="presentation" class="<?php echo $section === 'dialplan' ? 'active' : ''; ?>">
          <a href="#tab-dialplan" aria-controls="tab-dialplan" role="tab" data-toggle="tab">
            <i class="fa fa-code"></i> <?php echo _('Dialplan (preview)'); ?>
          </a>
        </li>
      </ul>

      <div class="tab-content">

        <!-- ========== TAB: Маппинги ========== -->
        <div role="tabpanel" class="tab-pane <?php echo $section === 'mappings' ? 'active' : ''; ?>" id="tab-mappings">
          <div class="panel panel-default">
            <div class="panel-heading">
              <h3 class="panel-title">
                <i class="fa fa-phone"></i>
                <?php echo _('Маппинг внутренний номер → CallerID'); ?>
              </h3>
            </div>
            <div class="panel-body">

              <!-- Форма добавления/редактирования маппинга -->
              <form method="post" id="form-mapping">
                <input type="hidden" name="action" value="save_mapping">
                <input type="hidden" name="section" value="mappings">
                <input type="hidden" name="display" value="outcidmap">
                <input type="hidden" name="mapping_id" id="mapping_id" value="<?php echo $editMapping ? $editMapping['id'] : ''; ?>">

                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label for="mapping_ext"><?php echo _('Внутренний номер'); ?> <span class="text-danger">*</span></label>
                      <?php if (!empty($coreExts)): ?>
                        <select name="mapping_ext" id="mapping_ext" class="form-control selectize">
                          <option value=""><?php echo _('— Выберите из списка или введите —'); ?></option>
                          <?php foreach ($coreExts as $ce): ?>
                            <option value="<?php echo htmlspecialchars($ce['extension']); ?>"
                              <?php echo ($editMapping && $editMapping['extension'] == $ce['extension']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($ce['extension'] . ' — ' . $ce['name']); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      <?php else: ?>
                        <input type="text" name="mapping_ext" id="mapping_ext" class="form-control"
                               placeholder="2001"
                               value="<?php echo $editMapping ? htmlspecialchars($editMapping['extension']) : ''; ?>">
                      <?php endif; ?>
                      <span class="help-block"><?php echo _('Номер внутренней линии'); ?></span>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label for="mapping_group"><?php echo _('Группа CallerID'); ?> <span class="text-danger">*</span></label>
                      <?php if (empty($groups)): ?>
                        <div class="alert alert-warning"><?php echo _('Сначала создайте хотя бы одну группу CallerID.'); ?></div>
                      <?php else: ?>
                        <select name="mapping_group" id="mapping_group" class="form-control">
                          <option value=""><?php echo _('— Выберите группу —'); ?></option>
                          <?php foreach ($groups as $g): ?>
                            <option value="<?php echo $g['id']; ?>"
                              <?php echo ($editMapping && $editMapping['group_id'] == $g['id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($g['name'] . ' (' . $g['callerid'] . ')'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label for="mapping_desc"><?php echo _('Описание (ФИО, должность)'); ?></label>
                      <input type="text" name="mapping_desc" id="mapping_desc" class="form-control"
                             placeholder="<?php echo _('Иванов И.И., каб. 312'); ?>"
                             value="<?php echo $editMapping ? htmlspecialchars($editMapping['description']) : ''; ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>&nbsp;</label>
                      <div>
                        <button type="submit" class="btn btn-primary btn-block" <?php echo empty($groups) ? 'disabled' : ''; ?>>
                          <i class="fa fa-save"></i>
                          <?php echo $editMapping ? _('Обновить') : _('Добавить'); ?>
                        </button>
                        <?php if ($editMapping): ?>
                          <a href="?display=outcidmap&section=mappings" class="btn btn-default btn-block">
                            <i class="fa fa-times"></i> <?php echo _('Отмена'); ?>
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </form>

              <hr>

              <!-- Таблица маппингов -->
              <?php if (empty($mappings)): ?>
                <div class="alert alert-info">
                  <i class="fa fa-info-circle"></i>
                  <?php echo _('Маппинги не настроены. Добавьте первый маппинг выше.'); ?>
                </div>
              <?php else: ?>
                <table class="table table-striped table-hover table-bordered" id="tbl-mappings">
                  <thead>
                    <tr>
                      <th><?php echo _('Внутренний номер'); ?></th>
                      <th><?php echo _('Имя (из FreePBX)'); ?></th>
                      <th><?php echo _('Описание'); ?></th>
                      <th><?php echo _('Группа CallerID'); ?></th>
                      <th><?php echo _('Внешний CallerID'); ?></th>
                      <th class="text-center"><?php echo _('Действия'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($mappings as $m): ?>
                      <tr>
                        <td><code><?php echo htmlspecialchars($m['extension']); ?></code></td>
                        <td><?php echo htmlspecialchars(isset($extNames[$m['extension']]) ? $extNames[$m['extension']] : '—'); ?></td>
                        <td><?php echo htmlspecialchars($m['description']); ?></td>
                        <td>
                          <span class="label label-default"><?php echo htmlspecialchars($m['group_name']); ?></span>
                        </td>
                        <td>
                          <span class="label label-success"><i class="fa fa-phone-square"></i> <?php echo htmlspecialchars($m['callerid']); ?></span>
                        </td>
                        <td class="text-center">
                          <a href="?display=outcidmap&action=edit_mapping&id=<?php echo $m['id']; ?>&section=mappings"
                             class="btn btn-xs btn-warning" title="<?php echo _('Изменить'); ?>">
                            <i class="fa fa-pencil"></i>
                          </a>
                          <form method="post" style="display:inline"
                                onsubmit="return confirm('<?php echo _('Удалить этот маппинг?'); ?>');">
                            <input type="hidden" name="action"     value="delete_mapping">
                            <input type="hidden" name="section"    value="mappings">
                            <input type="hidden" name="display"    value="outcidmap">
                            <input type="hidden" name="mapping_id" value="<?php echo $m['id']; ?>">
                            <button type="submit" class="btn btn-xs btn-danger" title="<?php echo _('Удалить'); ?>">
                              <i class="fa fa-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div><!-- /panel-body -->
          </div><!-- /panel -->
        </div><!-- /tab-mappings -->

        <!-- ========== TAB: Группы CallerID ========== -->
        <div role="tabpanel" class="tab-pane <?php echo $section === 'groups' ? 'active' : ''; ?>" id="tab-groups">
          <div class="panel panel-default">
            <div class="panel-heading">
              <h3 class="panel-title">
                <i class="fa fa-tag"></i>
                <?php echo _('Группы CallerID'); ?>
              </h3>
            </div>
            <div class="panel-body">

              <!-- Форма добавления/редактирования группы -->
              <form method="post" id="form-group">
                <input type="hidden" name="action"  value="save_group">
                <input type="hidden" name="section" value="groups">
                <input type="hidden" name="display" value="outcidmap">
                <input type="hidden" name="group_id" id="group_id" value="<?php echo $editGroup ? $editGroup['id'] : ''; ?>">

                <div class="row">
                  <div class="col-md-3">
                    <div class="form-group">
                      <label for="group_name"><?php echo _('Название группы'); ?> <span class="text-danger">*</span></label>
                      <input type="text" name="group_name" id="group_name" class="form-control"
                             placeholder="<?php echo _('Деканат 524'); ?>"
                             value="<?php echo $editGroup ? htmlspecialchars($editGroup['name']) : ''; ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <div class="form-group">
                      <label for="group_callerid"><?php echo _('Внешний CallerID (только цифры)'); ?> <span class="text-danger">*</span></label>
                      <input type="text" name="group_callerid" id="group_callerid" class="form-control"
                             placeholder="84951234567"
                             pattern="\d{6,15}"
                             title="<?php echo _('Только цифры, от 6 до 15 символов'); ?>"
                             value="<?php echo $editGroup ? htmlspecialchars($editGroup['callerid']) : ''; ?>">
                      <span class="help-block"><?php echo _('Номер который будет виден на принимающей стороне'); ?></span>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label for="group_desc"><?php echo _('Описание'); ?></label>
                      <input type="text" name="group_desc" id="group_desc" class="form-control"
                             placeholder="<?php echo _('Общий номер приёмной'); ?>"
                             value="<?php echo $editGroup ? htmlspecialchars($editGroup['description']) : ''; ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label>&nbsp;</label>
                      <div>
                        <button type="submit" class="btn btn-primary btn-block">
                          <i class="fa fa-save"></i>
                          <?php echo $editGroup ? _('Обновить') : _('Создать'); ?>
                        </button>
                        <?php if ($editGroup): ?>
                          <a href="?display=outcidmap&section=groups" class="btn btn-default btn-block">
                            <i class="fa fa-times"></i> <?php echo _('Отмена'); ?>
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </form>

              <hr>

              <!-- Таблица групп -->
              <?php if (empty($groups)): ?>
                <div class="alert alert-info">
                  <i class="fa fa-info-circle"></i>
                  <?php echo _('Групп пока нет. Создайте первую группу CallerID выше.'); ?>
                </div>
              <?php else: ?>
                <table class="table table-striped table-hover table-bordered">
                  <thead>
                    <tr>
                      <th><?php echo _('Название группы'); ?></th>
                      <th><?php echo _('CallerID'); ?></th>
                      <th><?php echo _('Описание'); ?></th>
                      <th><?php echo _('Номеров в группе'); ?></th>
                      <th class="text-center"><?php echo _('Действия'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    // Подсчёт номеров в каждой группе
                    $groupCounts = [];
                    foreach ($mappings as $m) {
                        $gid = $m['group_id'];
                        $groupCounts[$gid] = isset($groupCounts[$gid]) ? $groupCounts[$gid] + 1 : 1;
                    }
                    foreach ($groups as $g):
                        $cnt = isset($groupCounts[$g['id']]) ? $groupCounts[$g['id']] : 0;
                    ?>
                      <tr>
                        <td><strong><?php echo htmlspecialchars($g['name']); ?></strong></td>
                        <td>
                          <span class="label label-success"><i class="fa fa-phone-square"></i> <?php echo htmlspecialchars($g['callerid']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($g['description']); ?></td>
                        <td>
                          <span class="badge <?php echo $cnt > 0 ? 'badge-info' : ''; ?>"><?php echo $cnt; ?></span>
                        </td>
                        <td class="text-center">
                          <a href="?display=outcidmap&action=edit_group&id=<?php echo $g['id']; ?>&section=groups"
                             class="btn btn-xs btn-warning">
                            <i class="fa fa-pencil"></i>
                          </a>
                          <form method="post" style="display:inline"
                                onsubmit="return confirm('<?php echo _('Удалить группу? Все связанные маппинги будут также удалены.'); ?>');">
                            <input type="hidden" name="action"   value="delete_group">
                            <input type="hidden" name="section"  value="groups">
                            <input type="hidden" name="display"  value="outcidmap">
                            <input type="hidden" name="group_id" value="<?php echo $g['id']; ?>">
                            <button type="submit" class="btn btn-xs btn-danger">
                              <i class="fa fa-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div><!-- /panel-body -->
          </div><!-- /panel -->
        </div><!-- /tab-groups -->

        <!-- ========== TAB: Dialplan preview ========== -->
        <div role="tabpanel" class="tab-pane <?php echo $section === 'dialplan' ? 'active' : ''; ?>" id="tab-dialplan">
          <div class="panel panel-default">
            <div class="panel-heading">
              <h3 class="panel-title">
                <i class="fa fa-code"></i>
                <?php echo _('Предпросмотр сгенерированного dialplan'); ?>
              </h3>
            </div>
            <div class="panel-body">
              <p class="text-muted">
                <i class="fa fa-info-circle"></i>
                <?php echo _('Этот код будет добавлен в extensions_additional.conf при нажатии "Apply Config". Файл extensions_custom.conf трогать не нужно.'); ?>
              </p>
              <pre class="pre-scrollable" style="max-height:500px; font-size:12px; background:#1e1e1e; color:#d4d4d4; padding:16px; border-radius:4px;"><?php
                echo htmlspecialchars($module->generateDialplan('asterisk'));
              ?></pre>
            </div>
          </div>
        </div><!-- /tab-dialplan -->

      </div><!-- /tab-content -->
    </div><!-- /col -->
  </div><!-- /row -->
</div><!-- /container -->

<script src="<?php echo \FreePBX::Config()->get('ADMIN_DIRECTORY') ?>/modules/outcidmap/assets/js/outcidmap.js"></script>
