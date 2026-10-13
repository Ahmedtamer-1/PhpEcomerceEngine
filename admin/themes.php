<?php
/**
 * Admin — Theme Manager
 * Allows managing custom CSS themes and activating a primary theme.
 */
require_once __DIR__ . '/includes/auth.php';

// Migration to ensure the store_themes table exists
try {
    db()->exec("CREATE TABLE IF NOT EXISTS store_themes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        css_content TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    die("Database error during migration: " . $e->getMessage());
}

$pageTitle = 'Theme Manager';
$adminPage = 'themes';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $css = trim($_POST['css_content'] ?? '');

            if (!$name) {
                $error = "Theme name is required.";
            } else {
                if ($id > 0) {
                    $stmt = db()->prepare("UPDATE store_themes SET name = ?, css_content = ? WHERE id = ?");
                    $stmt->execute([$name, $css, $id]);
                    $success = "Theme '$name' updated successfully.";
                } else {
                    $stmt = db()->prepare("INSERT INTO store_themes (name, css_content) VALUES (?, ?)");
                    $stmt->execute([$name, $css]);
                    $success = "Theme '$name' created successfully.";
                }
            }
        } elseif ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->exec("UPDATE store_themes SET is_active = 0");
                $stmt = db()->prepare("UPDATE store_themes SET is_active = 1 WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Theme activated.";
            }
        } elseif ($action === 'deactivate') {
            db()->exec("UPDATE store_themes SET is_active = 0");
            $success = "Theme deactivated. Default styling will be used.";
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = db()->prepare("DELETE FROM store_themes WHERE id = ? AND is_active = 0");
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    $success = "Theme deleted.";
                } else {
                    $error = "Could not delete theme. It may be currently active.";
                }
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

$themes = db()->query("SELECT * FROM store_themes ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <h1 style="font-size: 1.25rem; margin:0;">Theme Manager</h1>
        <p style="font-size: 0.8rem; color: var(--text-muted); margin: 4px 0 0 0;">Manage and inject custom CSS into the storefront.</p>
    </div>
    <div class="admin-toolbar__right">
        <button class="btn-primary" onclick="openThemeEditor()">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:8px;"><path d="M12 5v14M5 12h14"/></svg>
            Create New Theme
        </button>
    </div>
</div>

<?php if ($success): ?>
<div class="admin-alert admin-alert--success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="admin-alert admin-alert--error"><?= h($error) ?></div>
<?php endif; ?>

<!-- Theme List View -->
<div id="themes-list">
    <?php if (empty($themes)): ?>
        <div class="card" style="text-align: center; padding: 40px;">
            <p style="color: var(--text-muted);">No custom themes found. Create one to get started!</p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
            <?php foreach ($themes as $theme): ?>
                <div class="card" style="display:flex; flex-direction:column; <?= $theme['is_active'] ? 'border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent);' : '' ?>">
                    <div style="display:flex; justify-content: space-between; align-items:flex-start; margin-bottom: 16px;">
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size: 1rem;"><?= h($theme['name']) ?></h3>
                            <span class="badge-status" style="<?= $theme['is_active'] ? 'background: var(--accent); color: var(--bg-primary);' : '' ?>">
                                <?= $theme['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">
                            <?= date('M j, Y', strtotime($theme['updated_at'])) ?>
                        </div>
                    </div>
                    
                    <div style="flex-grow:1; margin-bottom: 20px;">
                        <pre style="background: var(--bg-surface2); padding: 12px; border-radius: var(--radius-sm); font-size: 0.75rem; color: var(--text-muted); overflow: hidden; height: 80px; text-overflow: ellipsis; white-space: pre-wrap;"><?= h(substr($theme['css_content'], 0, 150)) . (strlen($theme['css_content']) > 150 ? '...' : '') ?></pre>
                    </div>

                    <div style="display:flex; gap: 8px; flex-wrap: wrap;">
                        <?php if ($theme['is_active']): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" class="admin-btn admin-btn--sm">Deactivate</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="id" value="<?= $theme['id'] ?>">
                                <button type="submit" class="admin-btn admin-btn--sm" style="color: var(--accent);">Activate</button>
                            </form>
                        <?php endif; ?>
                        
                        <button type="button" class="admin-btn admin-btn--sm" onclick="editTheme(<?= htmlspecialchars(json_encode($theme)) ?>)">Edit</button>
                        
                        <?php if (!$theme['is_active']): ?>
                            <form method="post" style="margin:0; margin-left:auto;" onsubmit="return confirm('Delete this theme permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $theme['id'] ?>">
                                <button type="submit" class="admin-btn admin-btn--sm" style="color: #ff4d4f;">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Theme Editor View -->
<div id="theme-editor" style="display:none;">
    <div style="margin-bottom:20px">
        <button class="admin-btn admin-btn--sm" style="opacity:.7" onclick="closeThemeEditor()">← Back to Themes</button>
    </div>
    <div class="card">
        <h2 id="theme-editor-title" style="font-size: 1.1rem; margin-top: 0; margin-bottom: 20px;">Create New Theme</h2>
        <form method="post" id="theme-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="theme-id" value="0">
            
            <div class="admin-form__group">
                <label>Theme Name</label>
                <input type="text" name="name" id="theme-name" required placeholder="e.g. Dark Mode Overrides">
            </div>

            <div class="admin-form__group">
                <label>Custom CSS</label>
                <textarea name="css_content" id="theme-css" required rows="20" style="font-family: monospace; font-size: 0.85rem; background: #1e1e1e; color: #d4d4d4; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-sm);"></textarea>
                <span class="admin-form__hint">Enter your custom CSS. This will be injected into the storefront's &lt;head&gt; when active. Do not include &lt;style&gt; tags.</span>
            </div>

            <div style="display:flex; gap: 12px; margin-top: 24px; justify-content: flex-end;">
                <button type="button" class="admin-btn" onclick="closeThemeEditor()">Cancel</button>
                <button type="submit" class="btn-primary">Save Theme</button>
            </div>
        </form>
    </div>
</div>

<script>
function openThemeEditor() {
    document.getElementById('themes-list').style.display = 'none';
    document.getElementById('theme-editor').style.display = 'block';
    
    document.getElementById('theme-editor-title').textContent = 'Create New Theme';
    document.getElementById('theme-id').value = '0';
    document.getElementById('theme-name').value = '';
    document.getElementById('theme-css').value = '/* Add your custom CSS here */\n\nbody {\n  \n}\n';
}

function editTheme(theme) {
    document.getElementById('themes-list').style.display = 'none';
    document.getElementById('theme-editor').style.display = 'block';
    
    document.getElementById('theme-editor-title').textContent = 'Edit Theme';
    document.getElementById('theme-id').value = theme.id;
    document.getElementById('theme-name').value = theme.name;
    document.getElementById('theme-css').value = theme.css_content;
}

function closeThemeEditor() {
    document.getElementById('themes-list').style.display = 'block';
    document.getElementById('theme-editor').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
