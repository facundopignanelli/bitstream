<?php
/**
 * ReBit Mappings Admin Interface
 * 
 * Clean, modern master-detail interface for managing ReBit domain mappings,
 * presets, search filtering, and curated icon picker.
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

$mappings = isset($mappings) && is_array($mappings) ? $mappings : BitStream_ReBit_Mappings::get_all_mappings();
$all_presets = BitStream_ReBit_Mappings::get_rebit_presets();

// Determine mapped domains for visual indicators
$mapped_domains = [];
foreach ($mappings as $m) {
    if (!empty($m['domain'])) {
        $mapped_domains[] = BitStream_ReBit_Mappings::normalize_domain($m['domain']);
    }
}

$rebit_nonce = wp_create_nonce('bitstream_rebit_mappings_nonce');
?>

<div class="bitstream-rebit-manager" data-nonce="<?php echo esc_attr($rebit_nonce); ?>" data-json-url="<?php echo esc_url(BITSTREAM_PLUGIN_URL . 'assets/json/fontawesome6_free.json'); ?>">
    <!-- Header & Action Bar -->
    <div class="bitstream-rebit-manager-header">
        <div class="bitstream-rebit-manager-intro">
            <h2 class="bitstream-rebit-manager-title"><?php esc_html_e('ReBit Mappings', 'bitstream'); ?></h2>
            <p class="bitstream-rebit-manager-desc"><?php esc_html_e('Customize how shared links and bookmarks appear on your timeline with domain-specific icons and action labels.', 'bitstream'); ?></p>
        </div>
        <div class="bitstream-rebit-manager-controls">
            <div class="bitstream-rebit-search-wrap">
                <i class="fa-solid fa-magnifying-glass bitstream-rebit-search-icon" aria-hidden="true"></i>
                <input type="search" class="bitstream-rebit-search-input" id="bitstream-rebit-search" placeholder="<?php esc_attr_e('Search mappings…', 'bitstream'); ?>" autocomplete="off" />
                <button type="button" class="bitstream-rebit-search-clear" id="bitstream-rebit-search-clear" aria-label="<?php esc_attr_e('Clear search', 'bitstream'); ?>" hidden>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <button type="button" class="bitstream-rebit-btn-add" id="bitstream-rebit-btn-add">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                <span><?php esc_html_e('Add Mapping', 'bitstream'); ?></span>
            </button>
            <button type="button" class="bitstream-rebit-btn-reset" id="bitstream-rebit-btn-reset" title="<?php esc_attr_e('Reset to factory defaults', 'bitstream'); ?>">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                <span><?php esc_html_e('Reset Defaults', 'bitstream'); ?></span>
            </button>
        </div>
    </div>

    <!-- Collapsible Inline Drawer for Add/Edit Mapping -->
    <div class="bitstream-rebit-drawer" id="bitstream-rebit-drawer" hidden>
        <div class="bitstream-rebit-drawer-card">
            <header class="bitstream-rebit-drawer-header">
                <h3 id="bitstream-rebit-drawer-title"><?php esc_html_e('Add New Mapping', 'bitstream'); ?></h3>
                <button type="button" class="bitstream-rebit-drawer-close" id="bitstream-rebit-drawer-close" aria-label="<?php esc_attr_e('Close drawer', 'bitstream'); ?>">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <!-- Popular Presets Shelf (Inside Drawer) -->
            <div class="bitstream-rebit-drawer-presets" id="bitstream-rebit-drawer-presets">
                <div class="bitstream-rebit-drawer-presets-header">
                    <div class="bitstream-rebit-drawer-presets-label">
                        <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                        <span><?php esc_html_e('Popular Presets (1-Click Fill):', 'bitstream'); ?></span>
                    </div>
                    <span class="bitstream-rebit-drawer-presets-tip"><?php esc_html_e('Click any preset to auto-fill the form', 'bitstream'); ?></span>
                </div>
                <div class="bitstream-rebit-drawer-presets-pills" id="bitstream-rebit-drawer-presets-pills">
                    <?php foreach ($all_presets as $key => $preset): 
                        $norm = BitStream_ReBit_Mappings::normalize_domain($preset['domain']);
                        $is_mapped = in_array($norm, $mapped_domains, true);
                    ?>
                        <button type="button" 
                                class="bitstream-preset-pill <?php echo $is_mapped ? 'is-mapped' : ''; ?>" 
                                data-preset-key="<?php echo esc_attr($key); ?>" 
                                data-domain="<?php echo esc_attr($preset['domain']); ?>" 
                                data-label="<?php echo esc_attr($preset['label']); ?>" 
                                data-icon="<?php echo esc_attr($preset['icon']); ?>"
                                data-mapped="<?php echo $is_mapped ? '1' : '0'; ?>"
                                title="<?php echo esc_attr(sprintf(__('Auto-fill with %s (%s)', 'bitstream'), $preset['label'], $preset['domain'])); ?>">
                            <i class="<?php echo esc_attr($preset['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></span>
                            <?php if ($is_mapped): ?>
                                <span class="bitstream-preset-pill-status" title="<?php esc_attr_e('Already in active mappings', 'bitstream'); ?>"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <form class="bitstream-rebit-drawer-form" id="bitstream-rebit-drawer-form">
                <input type="hidden" id="bitstream-rebit-mode" value="add" />
                <input type="hidden" id="bitstream-rebit-orig-domain" value="" />
                <div class="bitstream-rebit-drawer-grid">
                    <div class="bitstream-rebit-drawer-field">
                        <label for="bitstream-rebit-input-domain"><?php esc_html_e('Domain or URL', 'bitstream'); ?></label>
                        <input type="text" id="bitstream-rebit-input-domain" placeholder="example.com" required autocomplete="off" />
                        <span class="bitstream-rebit-field-tip"><?php esc_html_e('Auto-cleans http://, https://, and paths (e.g. spotify.com)', 'bitstream'); ?></span>
                    </div>
                    <div class="bitstream-rebit-drawer-field">
                        <label for="bitstream-rebit-input-label"><?php esc_html_e('Action Label', 'bitstream'); ?></label>
                        <input type="text" id="bitstream-rebit-input-label" placeholder="shared a link" required autocomplete="off" />
                        <span class="bitstream-rebit-field-tip"><?php esc_html_e('Shown on timeline card header (e.g. shared a video, shared a post)', 'bitstream'); ?></span>
                    </div>
                    <div class="bitstream-rebit-drawer-field">
                        <label for="bitstream-rebit-input-icon"><?php esc_html_e('Icon Class', 'bitstream'); ?></label>
                        <div class="bitstream-rebit-icon-input-row">
                            <div class="bitstream-rebit-icon-preview-mini" id="bitstream-rebit-icon-preview-mini">
                                <i class="fa-solid fa-link" aria-hidden="true"></i>
                            </div>
                            <input type="text" id="bitstream-rebit-input-icon" placeholder="fa-solid fa-link" value="fa-solid fa-link" required autocomplete="off" />
                            <button type="button" class="bitstream-rebit-btn-icon-pick" id="bitstream-rebit-btn-icon-pick" aria-label="<?php esc_attr_e('Browse Font Awesome icons', 'bitstream'); ?>">
                                <i class="fa-solid fa-icons" aria-hidden="true"></i>
                                <span><?php esc_html_e('Browse', 'bitstream'); ?></span>
                            </button>
                        </div>
                        <span class="bitstream-rebit-field-tip"><?php esc_html_e('Click Browse to search all Font Awesome icons, or enter any FA class directly', 'bitstream'); ?></span>
                    </div>
                    <div class="bitstream-rebit-drawer-field bitstream-rebit-drawer-field-preview">
                        <label><?php esc_html_e('Live Card Preview', 'bitstream'); ?></label>
                        <div class="bitstream-rebit-live-badge-preview" id="bitstream-rebit-live-preview">
                            <div class="bitstream-rebit-preview-badge">
                                <i class="fa-solid fa-link" id="bitstream-rebit-preview-icon"></i>
                            </div>
                            <div class="bitstream-rebit-preview-info">
                                <span class="bitstream-rebit-preview-label" id="bitstream-rebit-preview-label"><?php esc_html_e('shared a link', 'bitstream'); ?></span>
                                <span class="bitstream-rebit-preview-domain" id="bitstream-rebit-preview-domain">example.com</span>
                            </div>
                        </div>
                    </div>
                </div>
                <footer class="bitstream-rebit-drawer-footer">
                    <button type="button" class="bitstream-rebit-btn-cancel" id="bitstream-rebit-drawer-cancel"><?php esc_html_e('Cancel', 'bitstream'); ?></button>
                    <button type="submit" class="bitstream-rebit-btn-save" id="bitstream-rebit-drawer-save">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                        <span id="bitstream-rebit-drawer-save-text"><?php esc_html_e('Save Mapping', 'bitstream'); ?></span>
                    </button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Active Mappings Directory -->
    <div class="bitstream-rebit-directory">
        <div class="bitstream-rebit-directory-header">
            <h3 class="bitstream-rebit-directory-title">
                <span><?php esc_html_e('Active Mappings', 'bitstream'); ?></span>
                <span class="bitstream-rebit-count-badge" id="bitstream-rebit-count-badge"><?php echo count($mappings); ?></span>
            </h3>
        </div>
        <div class="bitstream-rebit-list" id="bitstream-rebit-list">
            <?php if (empty($mappings)): ?>
                <div class="bitstream-rebit-empty" id="bitstream-rebit-empty">
                    <div class="bitstream-rebit-empty-icon"><i class="fa-solid fa-sitemap" aria-hidden="true"></i></div>
                    <h4><?php esc_html_e('No mappings configured yet', 'bitstream'); ?></h4>
                    <p><?php esc_html_e('Add your favorite sites or import defaults to show branded icons when sharing links.', 'bitstream'); ?></p>
                    <button type="button" class="bitstream-rebit-btn-import-defaults" id="bitstream-rebit-btn-empty-import">
                        <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                        <span><?php esc_html_e('Import Default Mappings', 'bitstream'); ?></span>
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($mappings as $map): ?>
                    <div class="bitstream-rebit-item" data-domain="<?php echo esc_attr($map['domain']); ?>" data-label="<?php echo esc_attr($map['label']); ?>" data-icon="<?php echo esc_attr($map['icon']); ?>">
                        <div class="bitstream-rebit-item-badge">
                            <i class="<?php echo esc_attr($map['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="bitstream-rebit-item-details">
                            <span class="bitstream-rebit-item-label"><?php echo esc_html($map['label']); ?></span>
                            <span class="bitstream-rebit-item-domain"><?php echo esc_html($map['domain']); ?></span>
                        </div>
                        <div class="bitstream-rebit-item-actions">
                            <button type="button" class="bitstream-rebit-action-btn bitstream-rebit-action-edit" title="<?php esc_attr_e('Edit mapping', 'bitstream'); ?>" aria-label="<?php esc_attr_e('Edit', 'bitstream'); ?>">
                                <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="bitstream-rebit-action-btn bitstream-rebit-action-delete" title="<?php esc_attr_e('Delete mapping', 'bitstream'); ?>" aria-label="<?php esc_attr_e('Delete', 'bitstream'); ?>">
                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="bitstream-rebit-no-search-results" id="bitstream-rebit-no-search-results" hidden>
            <p><?php esc_html_e('No mappings matching your search.', 'bitstream'); ?></p>
        </div>
    </div>

    <!-- Undo Toast Notification -->
    <div class="bitstream-rebit-undo-toast" id="bitstream-rebit-undo-toast" hidden>
        <span class="bitstream-rebit-undo-text" id="bitstream-rebit-undo-text"></span>
        <button type="button" class="bitstream-rebit-undo-btn" id="bitstream-rebit-undo-btn"><?php esc_html_e('Undo', 'bitstream'); ?></button>
    </div>

    <!-- Font Awesome Icon Picker Modal -->
    <div class="bitstream-composer-modal bitstream-composer-modal-icon-picker" id="bitstream-rebit-icon-modal" hidden>
        <div class="bitstream-composer-modal-backdrop" id="bitstream-rebit-icon-backdrop"></div>
        <div class="bitstream-composer-modal-dialog bitstream-composer-modal-dialog-wide bitstream-icon-picker-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Select an Icon', 'bitstream'); ?>">
            <header class="bitstream-composer-modal-header">
                <h3><i class="fa-solid fa-icons" aria-hidden="true"></i> <span><?php esc_html_e('Select an Icon', 'bitstream'); ?></span></h3>
                <button type="button" class="bitstream-composer-modal-close" id="bitstream-rebit-icon-close" aria-label="<?php esc_attr_e('Close icon picker', 'bitstream'); ?>">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <div class="bitstream-icon-picker-controls">
                <div class="bitstream-icon-picker-search-wrap">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" id="bitstream-rebit-icon-search" placeholder="<?php esc_attr_e('Search all Font Awesome icons (e.g. twitter, music, link)…', 'bitstream'); ?>" autocomplete="off" />
                    <button type="button" id="bitstream-rebit-icon-search-clear" class="bitstream-icon-search-clear" aria-label="<?php esc_attr_e('Clear search', 'bitstream'); ?>" hidden>
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="bitstream-icon-picker-tabs" role="tablist">
                    <button type="button" class="bitstream-icon-category is-active" data-category="all"><?php esc_html_e('All', 'bitstream'); ?> <span class="bitstream-icon-cat-count" id="bitstream-cat-count-all"></span></button>
                    <button type="button" class="bitstream-icon-category" data-category="brands"><?php esc_html_e('Brands', 'bitstream'); ?> <span class="bitstream-icon-cat-count" id="bitstream-cat-count-brands"></span></button>
                    <button type="button" class="bitstream-icon-category" data-category="solid"><?php esc_html_e('Solid', 'bitstream'); ?> <span class="bitstream-icon-cat-count" id="bitstream-cat-count-solid"></span></button>
                    <button type="button" class="bitstream-icon-category" data-category="regular"><?php esc_html_e('Regular', 'bitstream'); ?> <span class="bitstream-icon-cat-count" id="bitstream-cat-count-regular"></span></button>
                </div>
            </div>
            <div class="bitstream-composer-modal-body bitstream-icon-picker-body" id="bitstream-rebit-icon-body">
                <div class="bitstream-icon-grid" id="bitstream-rebit-icon-grid">
                    <!-- Populated dynamically from fontawesome6_free.json -->
                </div>
            </div>
            <footer class="bitstream-composer-modal-footer bitstream-icon-picker-footer">
                <span class="bitstream-icon-picker-status" id="bitstream-icon-picker-status"></span>
                <button type="button" class="bitstream-composer-modal-cancel" id="bitstream-rebit-icon-cancel"><?php esc_html_e('Cancel', 'bitstream'); ?></button>
            </footer>
        </div>
    </div>
</div>
