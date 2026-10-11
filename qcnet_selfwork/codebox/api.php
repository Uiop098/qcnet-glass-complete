<?php
/**
 * api.php — single-endpoint JSON API for QuickChat Code Box's project storage.
 * Every request is POST {action, ...} (a couple of read-only actions also
 * accept GET for convenience). No accounts: possession of the project token
 * (an unguessable ~22 char id, kept in the page URL + localStorage) is what
 * grants access, the same trust model as a shared/unlisted link.
 */

require __DIR__ . '/common.php';

$in = cb_input();
$action = $in['action'] ?? '';

switch ($action) {

    /* ---------------- create_project ----------------
       In:  (nothing required)
       Does: mints a fresh token and an empty project directory. The actual
       seed file tree lives client-side (seedProject() in app.js) and is
       written on the first save — the server doesn't need to know its shape.
       Out: token */
    case 'create_project': {
        $token = cb_new_token();
        $dir = cb_project_dir($token, true);
        cb_ensure_dir($dir . '/checkpoints');
        cb_write_json_atomic($dir . '/meta.json', [
            'created_at' => time(),
            'last_active' => time(),
        ]);
        cb_ok(['token' => $token]);
        break;
    }

    /* ---------------- load_state ----------------
       In:  token
       Does: returns the last saved {project, settings, workspace} bundle for
       this token, or ok:true with state:null if nothing has been saved yet
       (e.g. a token was minted but the first save hasn't happened).
       Out: state */
    case 'load_state': {
        $token = $in['token'] ?? '';
        $dir = cb_project_dir($token);
        $state = cb_read_json($dir . '/state.json', null);
        cb_ok(['state' => $state]);
        break;
    }

    /* ---------------- save_state ----------------
       In:  token, state ({project, settings, workspace})
       Does: overwrites state.json for this token and bumps last_active.
       Debounced client-side (~500ms after the last edit) so this isn't
       called on every keystroke. */
    case 'save_state': {
        $token = $in['token'] ?? '';
        $state = $in['state'] ?? null;
        if (!is_array($state)) cb_error('Missing state.');
        $dir = cb_project_dir($token, true);
        cb_write_json_atomic($dir . '/state.json', $state);
        $meta = cb_read_json($dir . '/meta.json', ['created_at' => time()]);
        $meta['last_active'] = time();
        cb_write_json_atomic($dir . '/meta.json', $meta);
        cb_ok();
        break;
    }

    /* ---------------- save_checkpoint ----------------
       In:  token, label (optional), project (full file tree)
       Does: snapshots the current project tree into checkpoints/ — this is
       what backs the "Checkpoints" section of the Source Control panel and
       the project's own checkpoints/ folder in the file explorer. */
    case 'save_checkpoint': {
        $token = $in['token'] ?? '';
        $project = $in['project'] ?? null;
        if (!is_array($project)) cb_error('Missing project.');
        $dir = cb_project_dir($token, true);
        cb_ensure_dir($dir . '/checkpoints');
        $label = trim((string)($in['label'] ?? ''));
        $slug = $label !== '' ? preg_replace('/[^a-zA-Z0-9_-]+/', '-', $label) : 'checkpoint';
        $slug = substr(trim($slug, '-'), 0, 40) ?: 'checkpoint';
        $time = time();
        $file = "{$time}_{$slug}.json";
        cb_write_json_atomic($dir . '/checkpoints/' . $file, [
            'label' => $label !== '' ? $label : null,
            'time' => $time,
            'project' => $project,
        ]);
        cb_ok(['file' => $file]);
        break;
    }

    /* ---------------- list_checkpoints ----------------
       In:  token
       Out: checkpoints [{file, label, time}]  (newest last) */
    case 'list_checkpoints': {
        $token = $in['token'] ?? '';
        $dir = cb_project_dir($token) . '/checkpoints';
        $out = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..' || !str_ends_with($f, '.json')) continue;
                $data = cb_read_json($dir . '/' . $f, null);
                if ($data) $out[] = ['file' => $f, 'label' => $data['label'] ?? null, 'time' => $data['time'] ?? 0];
            }
            usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
        }
        cb_ok(['checkpoints' => $out]);
        break;
    }

    /* ---------------- restore_checkpoint ----------------
       In:  token, file (from list_checkpoints)
       Does: reads that checkpoint's project tree and writes it back over the
       live state.json, so a reload also picks it up.
       Out: project */
    case 'restore_checkpoint': {
        $token = $in['token'] ?? '';
        $file = basename((string)($in['file'] ?? '')); // basename() blocks path traversal
        $dir = cb_project_dir($token);
        $cp = cb_read_json($dir . '/checkpoints/' . $file, null);
        if (!$cp) cb_error('Checkpoint not found.', 404);
        $state = cb_read_json($dir . '/state.json', ['project' => null, 'settings' => new stdClass(), 'workspace' => new stdClass()]);
        $state['project'] = $cp['project'];
        cb_write_json_atomic($dir . '/state.json', $state);
        cb_ok(['project' => $cp['project']]);
        break;
    }

    /* ---------------- delete_project ----------------
       In:  token
       Does: permanently deletes the project directory (state + checkpoints). */
    case 'delete_project': {
        $token = $in['token'] ?? '';
        $dir = cb_project_dir($token);
        cb_rrmdir($dir);
        cb_ok();
        break;
    }

    default:
        cb_error('Unknown action.', 404);
}
