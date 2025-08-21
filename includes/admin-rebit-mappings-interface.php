<?php
/**
 * ReBit Mappings Admin Interface
 * 
 * This file contains the HTML/CSS/JS for the ReBit mappings admin page
 * Separated for better organization and maintainability
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

?>
<!-- Load Font Awesome for icon picker -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />

<style>
/* Clean, simple layout without aggressive overflow controls */

/* Basic responsive layout */
.flex-container {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 20px;
}

.flex-item {
    flex: 1;
    min-width: 300px;
}

/* Simple card styling */
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.card .title {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 1.1em;
}

/* Full-width mappings container */
.mappings-container {
    width: 100%;
    max-width: none;
    margin: 0;
}

/* Clean mapping rows - wider spacing for better use of space */
.mapping-row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    padding: 20px;
    margin-bottom: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fafafa;
    width: 100%;
}

/* Better field distribution for wider layout */
.mapping-field {
    flex: 1 1 200px;
    min-width: 200px;
}

.mapping-field.preview-field {
    flex: 1.5 1 250px;
    min-width: 250px;
}

.mapping-field.actions-field {
    flex: 0.7 1 140px;
    min-width: 140px;
}

.mapping-field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.mapping-field input[type="text"],
.mapping-field select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    box-sizing: border-box;
}

/* Icon picker styling */
.icon-input-container {
    position: relative;
}

.icon-picker-button {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: #f0f0f1;
    border: 1px solid #8c8f94;
    border-radius: 3px;
    padding: 4px 8px;
    cursor: pointer;
    height: 28px;
    width: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.icon-picker-button:hover {
    background: #e0e0e1;
}

/* Modal styling */
#icon-picker-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999999;
}

#icon-picker-modal.show {
    display: flex;
}

.icon-picker-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.icon-picker-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.icon-picker-header h3 {
    margin: 0;
}

.icon-picker-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.icon-picker-search {
    padding: 10px 20px;
    border-bottom: 1px solid #ddd;
}

.icon-picker-search input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

.icon-picker-tabs {
    padding: 10px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    gap: 10px;
}

.icon-category {
    padding: 8px 16px;
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s ease;
}

.icon-category:hover {
    background: #e8f4f0;
    border-color: #2c6e49;
}

.icon-category.active {
    background: #2c6e49;
    color: white;
    border-color: #2c6e49;
}

#icon-grid {
    padding: 20px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 10px;
    max-height: 400px;
    overflow-y: auto;
}

/* Loading indicator styling */
.icon-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
    color: #666;
    grid-column: 1 / -1;
    width: 100%;
}

.icon-loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #2c6e49;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 15px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.icon-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-align: center;
    background: white;
}

.icon-option:hover {
    background: #f0f0f0;
    border-color: #0073aa;
}

.icon-option i {
    font-size: 20px;
    margin-bottom: 5px;
    color: #444;
}

.icon-option span {
    font-size: 10px;
    color: #666;
    word-break: break-all;
}

/* Preview styling */
.mapping-preview {
    display: flex;
    align-items: center;
    padding: 10px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-top: 10px;
}

.mapping-preview i {
    margin-right: 8px;
    color: #2c6e49;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .flex-container {
        flex-direction: column;
    }
    
    .flex-item {
        min-width: 100%;
    }
    
    .mapping-row {
        flex-direction: column;
    }
    
    #icon-grid {
        grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
    }
}
</style>

<div class="wrap">
    <h1>ReBit Mappings</h1>
    <p class="description">Configure how different websites appear when shared as ReBits. Each mapping adds a custom icon and label for specific domains.</p>
    
    <!-- Import Default Mappings -->
    <?php if (empty($mappings)): ?>
    <div style="background: #e7f3ff; border: 1px solid #72aee6; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
        <h3 style="margin-top: 0;">🚀 Get Started Quickly</h3>
        <p>Import popular website mappings to get started immediately with Twitter, YouTube, GitHub, and more!</p>
        <form method="post" style="display: inline;">
            <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
            <button type="submit" name="import_defaults" class="button button-primary">Import Default Mappings</button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Top Section: Quick Add and Add New Mapping -->
    <div class="flex-container">
        <!-- Quick Presets Section -->
        <div class="card flex-item">
            <h2 class="title">Quick Add Popular Sites</h2>
            <p>Add pre-configured mappings for popular websites:</p>
            <form method="post" style="margin-bottom: 15px;">
                <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                <div style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <label><strong>Website:</strong></label><br>
                        <select name="preset_selection" style="width: 100%; box-sizing: border-box;">
                            <option value="">Select a website...</option>
                            <?php foreach ($this->get_rebit_presets() as $key => $preset): ?>
                                <option value="<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($preset['label']); ?> (<?php echo esc_html($preset['domain']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 0 0 auto;">
                        <button type="submit" name="add_preset" class="button button-secondary">Add Preset</button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Add New Mapping Section -->
        <div class="card flex-item">
            <h2 class="title">Add New Mapping</h2>
            <form method="post">
                <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                <div style="margin-bottom: 15px;">
                    <label><strong>Domain:</strong></label><br>
                    <input type="text" name="bitstream_rebit_mappings[new][domain]" 
                           placeholder="example.com" style="width: 100%; box-sizing: border-box;" 
                           oninput="updateNewMappingPreview()" />
                    <small class="description">Enter just the domain (e.g., "twitter.com")</small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label><strong>Label:</strong></label><br>
                    <input type="text" name="bitstream_rebit_mappings[new][label]" 
                           placeholder="shared a Tweet" style="width: 100%; box-sizing: border-box;" 
                           oninput="updateNewMappingPreview()" />
                    <small class="description">Text shown when sharing from this site (e.g., "shared a Tweet", "shared a video")</small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label><strong>Icon Class:</strong></label><br>
                    <div class="icon-input-container">
                        <input type="text" name="bitstream_rebit_mappings[new][icon]" 
                               placeholder="fas fa-link" 
                               id="new-icon-input" 
                               oninput="updateNewMappingPreview()" />
                        <button type="button" class="icon-picker-button" onclick="openIconPicker('new-icon-input')">
                            <i class="fas fa-palette"></i>
                        </button>
                    </div>
                    <small class="description">Font Awesome class or use the icon picker</small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label><strong>Preview:</strong></label><br>
                    <div class="mapping-preview" id="new-mapping-preview">
                        <i class="fas fa-link" style="margin-right: 8px; color: #2c6e49;"></i>
                        <span id="new-mapping-preview-text">shared from</span>
                    </div>
                </div>
                <p class="submit" style="margin-top: 15px;">
                    <input type="submit" name="submit" class="button-primary" value="Add Mapping" />
                </p>
            </form>
        </div>
    </div>
    
    <!-- Current Mappings -->
    <div class="mappings-container">
        <form method="post" id="mappings-form">
            <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
            
            <h2 style="margin-top: 30px; margin-bottom: 15px; font-size: 1.3em;">Current Mappings</h2>
            
            <?php if (empty($mappings)): ?>
                <div class="card">
                    <p class="description">No mappings configured yet. Use the sections above to add mappings.</p>
                </div>
            <?php else: ?>
                <div id="mappings-container" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                    <?php foreach ($mappings as $i => $map): ?>
                        <div class="mapping-row">
                            <div class="mapping-field">
                                <label><strong>Domain:</strong></label><br>
                                <input type="text" name="bitstream_rebit_mappings[existing][<?php echo $i; ?>][domain]" 
                                       value="<?php echo esc_attr($map['domain']); ?>" 
                                       placeholder="example.com" />
                            </div>
                            <div class="mapping-field">
                                <label><strong>Label:</strong></label><br>
                                <input type="text" name="bitstream_rebit_mappings[existing][<?php echo $i; ?>][label]" 
                                       value="<?php echo esc_attr($map['label']); ?>" 
                                       placeholder="shared a Tweet" />
                            </div>
                            <div class="mapping-field">
                                <label><strong>Icon Class:</strong></label><br>
                                <div class="icon-input-container">
                                    <input type="text" name="bitstream_rebit_mappings[existing][<?php echo $i; ?>][icon]" 
                                           value="<?php echo esc_attr($map['icon']); ?>" 
                                           placeholder="fab fa-twitter" 
                                           id="icon-input-<?php echo $i; ?>" />
                                    <button type="button" class="icon-picker-button" onclick="openIconPicker('icon-input-<?php echo $i; ?>')">
                                        <i class="fas fa-palette"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mapping-field preview-field">
                                <label><strong>Preview:</strong></label><br>
                                <div class="mapping-preview">
                                    <i class="<?php echo esc_attr($map['icon']); ?>" style="margin-right: 8px; color: #2c6e49;"></i>
                                    <span><?php echo esc_html($map['label']); ?></span>
                                </div>
                            </div>
                            <div class="mapping-field actions-field">
                                <label><strong>&nbsp;</strong></label><br>
                                <button type="button" class="button button-link-delete" onclick="removeMapping(this)" style="color: #a00;">Remove</button>
                                <input type="hidden" name="bitstream_rebit_mappings[<?php echo $i; ?>][remove]" value="0" class="remove-flag" />
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <p class="submit" style="margin-top: 20px;">
                    <input type="submit" name="submit" class="button-primary" value="Save All Mappings" />
                </p>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Icon Picker Modal -->
    <div id="icon-picker-modal">
        <div class="icon-picker-content">
            <div class="icon-picker-header">
                <h3>Select an Icon</h3>
                <button type="button" class="icon-picker-close" onclick="closeIconPicker()">&times;</button>
            </div>
            
            <div class="icon-picker-search">
                <input type="text" id="icon-search" placeholder="Search icons..." onkeyup="filterIcons()" />
            </div>
            
            <div class="icon-picker-tabs">
                <button type="button" class="icon-category active" onclick="showCategory('all')" data-category="all">All</button>
                <button type="button" class="icon-category" onclick="showCategory('brands')" data-category="brands">Brands</button>
                <button type="button" class="icon-category" onclick="showCategory('solid')" data-category="solid">Solid</button>
                <button type="button" class="icon-category" onclick="showCategory('regular')" data-category="regular">Regular</button>
            </div>
            
            <div id="icon-grid">
                <!-- Icons will be populated by JavaScript -->
            </div>
        </div>
    </div>
    
    <p class="description" style="margin-top: 20px;">
        <strong>Icon Help:</strong> Use the icon picker button or manually enter <a href="https://fontawesome.com/icons" target="_blank">Font Awesome icons</a>.
    </p>
</div>

<script>
// Global variables for icon picker - attach to window for global access
window.currentIconInput = null;
window.iconLibrary = { brands: [], solid: [], regular: [] };
window.iconsLoaded = false;

// Make variables accessible in local scope too
var currentIconInput = window.currentIconInput;
var iconLibrary = window.iconLibrary;
var iconsLoaded = window.iconsLoaded;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('BitStream: DOM loaded, setting up icon picker...');
    updateNewMappingPreview(); // Initialize the preview
});

// Function to dynamically load Font Awesome icons from JSON file
function loadFontAwesomeIcons() {
    if (iconsLoaded) return Promise.resolve();
    
    return new Promise((resolve, reject) => {
        console.log('Loading Font Awesome icons from JSON file...');
        
        // Try to load from JSON file first
        fetch('<?php echo plugin_dir_url(__FILE__) . '../assets/json/fontawesome6_free.json'; ?>')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load JSON file');
                }
                return response.json();
            })
            .then(data => {
                console.log('Successfully loaded icons from JSON:', data);
                window.iconLibrary = iconLibrary = data;
                window.iconsLoaded = iconsLoaded = true;
                console.log('Loaded', data.brands?.length || 0, 'brand icons,', data.solid?.length || 0, 'solid icons,', data.regular?.length || 0, 'regular icons');
                resolve();
            })
            .catch(error => {
                console.log('Failed to load JSON file, using fallback:', error);
                // Fall back to hardcoded icons
                window.iconLibrary = iconLibrary = getFallbackIcons();
                window.iconsLoaded = iconsLoaded = true;
                console.log('Using fallback icon library with', iconLibrary.brands.length + iconLibrary.solid.length + iconLibrary.regular.length, 'icons');
                resolve();
            });
    });
}

// Make loadFontAwesomeIcons globally accessible
window.loadFontAwesomeIcons = loadFontAwesomeIcons;

function closeIconPicker() {
    const modal = document.getElementById('icon-picker-modal');
    modal.style.display = 'none';
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    window.currentIconInput = currentIconInput = null;
}

function filterIcons() {
    const searchTerm = document.getElementById('icon-search').value.toLowerCase();
    const iconOptions = document.querySelectorAll('.icon-option');
    
    iconOptions.forEach(option => {
        const iconText = option.textContent.toLowerCase();
        if (iconText.includes(searchTerm)) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
}

function selectIcon(iconClass) {
    if (currentIconInput) {
        currentIconInput.value = iconClass;
        
        // Update preview if it exists nearby
        const mappingRow = currentIconInput.closest('.mapping-row');
        if (mappingRow) {
            const preview = mappingRow.querySelector('.mapping-preview i');
            if (preview) {
                preview.className = iconClass;
            }
        }
        
        // Update new mapping preview if this is the new mapping input
        if (currentIconInput.id === 'new-icon-input') {
            updateNewMappingPreview();
        }
    }
    closeIconPicker();
}

// Function to show loading indicator in the icon grid
function showLoadingIndicator() {
    const grid = document.getElementById('icon-grid');
    grid.innerHTML = `
        <div class="icon-loading">
            <div class="icon-loading-spinner"></div>
            <p>Loading Font Awesome icons...</p>
            <small>This may take a few seconds</small>
        </div>
    `;
}

// Function to update the preview for new mapping as user types
function updateNewMappingPreview() {
    const domainInput = document.querySelector('input[name="bitstream_rebit_mappings[new][domain]"]');
    const labelInput = document.querySelector('input[name="bitstream_rebit_mappings[new][label]"]');
    const iconInput = document.getElementById('new-icon-input');
    const previewIcon = document.querySelector('#new-mapping-preview i');
    const previewText = document.getElementById('new-mapping-preview-text');
    
    if (previewIcon && previewText) {
        // Update icon
        const iconClass = iconInput?.value?.trim() || 'fas fa-link';
        previewIcon.className = iconClass;
        
        // Update text
        const label = labelInput?.value?.trim();
        const domain = domainInput?.value?.trim();
        
        if (label) {
            previewText.textContent = label;
        } else if (domain) {
            previewText.textContent = `shared from ${domain}`;
        } else {
            previewText.textContent = 'shared from';
        }
    }
}

function removeMapping(button) {
    const row = button.closest('.mapping-row');
    const removeFlag = row.querySelector('.remove-flag');
    row.style.opacity = '0.5';
    row.style.textDecoration = 'line-through';
    removeFlag.value = '1';
    button.textContent = 'Undo';
    button.onclick = function() { undoRemove(this); };
}

function undoRemove(button) {
    const row = button.closest('.mapping-row');
    const removeFlag = row.querySelector('.remove-flag');
    row.style.opacity = '1';
    row.style.textDecoration = 'none';
    removeFlag.value = '0';
    button.textContent = 'Remove';
    button.onclick = function() { removeMapping(this); };
}

// Comprehensive fallback icon list in case CSS parsing fails
function getFallbackIcons() {
    return {
        brands: [
            'fab fa-500px', 'fab fa-accessible-icon', 'fab fa-accusoft', 'fab fa-adn', 'fab fa-adobe', 'fab fa-adversal',
            'fab fa-affiliatetheme', 'fab fa-airbnb', 'fab fa-algolia', 'fab fa-amazon', 'fab fa-amazon-pay', 'fab fa-amilia',
            'fab fa-android', 'fab fa-angellist', 'fab fa-angrycreative', 'fab fa-angular', 'fab fa-app-store', 'fab fa-app-store-ios',
            'fab fa-apper', 'fab fa-apple', 'fab fa-apple-pay', 'fab fa-artstation', 'fab fa-asymmetrik', 'fab fa-atlassian',
            'fab fa-audible', 'fab fa-autoprefixer', 'fab fa-avianex', 'fab fa-aviato', 'fab fa-aws', 'fab fa-bandcamp',
            'fab fa-battle-net', 'fab fa-behance', 'fab fa-behance-square', 'fab fa-bimobject', 'fab fa-bitbucket', 'fab fa-bitcoin',
            'fab fa-bity', 'fab fa-black-tie', 'fab fa-blackberry', 'fab fa-blogger', 'fab fa-blogger-b', 'fab fa-bluetooth',
            'fab fa-bluetooth-b', 'fab fa-bootstrap', 'fab fa-btc', 'fab fa-buffer', 'fab fa-buromobelexperte', 'fab fa-buy-n-large',
            'fab fa-buysellads', 'fab fa-canadian-maple-leaf', 'fab fa-cc-amazon-payments', 'fab fa-cc-amex', 'fab fa-cc-apple-pay',
            'fab fa-cc-diners-club', 'fab fa-cc-discover', 'fab fa-cc-jcb', 'fab fa-cc-mastercard', 'fab fa-cc-paypal',
            'fab fa-cc-stripe', 'fab fa-cc-visa', 'fab fa-centercode', 'fab fa-centos', 'fab fa-chrome', 'fab fa-chromecast',
            'fab fa-facebook', 'fab fa-github', 'fab fa-twitter', 'fab fa-youtube', 'fab fa-linkedin', 'fab fa-instagram'
        ],
        solid: [
            'fas fa-ad', 'fas fa-address-book', 'fas fa-address-card', 'fas fa-adjust', 'fas fa-air-freshener', 'fas fa-align-center',
            'fas fa-align-justify', 'fas fa-align-left', 'fas fa-align-right', 'fas fa-allergies', 'fas fa-ambulance', 'fas fa-american-sign-language-interpreting',
            'fas fa-anchor', 'fas fa-angle-double-down', 'fas fa-angle-double-left', 'fas fa-angle-double-right', 'fas fa-angle-double-up', 'fas fa-angle-down',
            'fas fa-angle-left', 'fas fa-angle-right', 'fas fa-angle-up', 'fas fa-angry', 'fas fa-ankh', 'fas fa-apple-alt',
            'fas fa-archive', 'fas fa-archway', 'fas fa-arrow-alt-circle-down', 'fas fa-arrow-alt-circle-left', 'fas fa-arrow-alt-circle-right', 'fas fa-arrow-alt-circle-up',
            'fas fa-arrow-circle-down', 'fas fa-arrow-circle-left', 'fas fa-arrow-circle-right', 'fas fa-arrow-circle-up', 'fas fa-arrow-down', 'fas fa-arrow-left',
            'fas fa-arrow-right', 'fas fa-arrow-up', 'fas fa-arrows-alt', 'fas fa-arrows-alt-h', 'fas fa-arrows-alt-v', 'fas fa-assistive-listening-systems',
            'fas fa-asterisk', 'fas fa-at', 'fas fa-atlas', 'fas fa-atom', 'fas fa-audio-description', 'fas fa-award',
            'fas fa-baby', 'fas fa-baby-carriage', 'fas fa-backspace', 'fas fa-backward', 'fas fa-bacon', 'fas fa-bacteria',
            'fas fa-link', 'fas fa-home', 'fas fa-user', 'fas fa-heart', 'fas fa-comment', 'fas fa-newspaper'
        ],
        regular: [
            'far fa-address-book', 'far fa-address-card', 'far fa-angry', 'far fa-arrow-alt-circle-down', 'far fa-arrow-alt-circle-left', 'far fa-arrow-alt-circle-right',
            'far fa-arrow-alt-circle-up', 'far fa-bell', 'far fa-bell-slash', 'far fa-bookmark', 'far fa-building', 'far fa-calendar',
            'far fa-calendar-alt', 'far fa-calendar-check', 'far fa-calendar-minus', 'far fa-calendar-plus', 'far fa-calendar-times', 'far fa-caret-square-down',
            'far fa-caret-square-left', 'far fa-caret-square-right', 'far fa-caret-square-up', 'far fa-chart-bar', 'far fa-check-circle', 'far fa-check-square',
            'far fa-circle', 'far fa-clipboard', 'far fa-clock', 'far fa-clone', 'far fa-closed-captioning', 'far fa-comment',
            'far fa-comment-alt', 'far fa-comment-dots', 'far fa-comments', 'far fa-compass', 'far fa-copy', 'far fa-copyright',
            'far fa-credit-card', 'far fa-dizzy', 'far fa-dot-circle', 'far fa-edit', 'far fa-envelope', 'far fa-envelope-open',
            'far fa-eye', 'far fa-eye-slash', 'far fa-file', 'far fa-file-alt', 'far fa-file-archive', 'far fa-file-audio'
        ]
    };
}

// Make getFallbackIcons globally accessible
window.getFallbackIcons = getFallbackIcons;

function openIconPicker(inputId) {
    console.log('=== ICON PICKER DEBUG START ===');
    console.log('openIconPicker called with inputId:', inputId);
    
    const inputElement = document.getElementById(inputId);
    console.log('Input element found:', inputElement);
    
    const modal = document.getElementById('icon-picker-modal');
    console.log('Modal element found:', modal);
    
    if (!modal) {
        console.error('Modal not found!');
        return;
    }
    
    if (!inputElement) {
        console.error('Input element not found!');
        return;
    }
    
    // Store reference to current input
    window.currentIconInput = inputElement;
    console.log('Current input stored:', window.currentIconInput);
    
    // Show modal with flexbox centering
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.classList.add('show');
    console.log('Modal should be visible now. Display:', modal.style.display);
    
    // Prevent background scrolling
    document.body.style.overflow = 'hidden';
    
    // Show loading indicator immediately
    showLoadingIndicator();
    
    console.log('=== ICON PICKER DEBUG END ===');
    
    // If icons not loaded yet, load from JSON file
    if (!window.iconsLoaded) {
        console.log('Loading icons from JSON file...');
        
        loadFontAwesomeIcons().then(() => {
            console.log('Successfully loaded icons from JSON');
            showCategory('all');
            document.getElementById('icon-search').value = '';
        }).catch(e => {
            console.log('Failed to load icons, using fallback:', e);
            window.iconLibrary = iconLibrary = getFallbackIcons();
            window.iconsLoaded = iconsLoaded = true;
            showCategory('all');
            document.getElementById('icon-search').value = '';
        });
    } else {
        // Icons already loaded, just show them
        showCategory('all');
        document.getElementById('icon-search').value = '';
    }
}

// Make functions globally accessible
window.openIconPicker = openIconPicker;
window.updateNewMappingPreview = updateNewMappingPreview;
window.showLoadingIndicator = showLoadingIndicator;

function showCategory(category) {
    console.log('Showing category:', category);
    console.log('Available icon library:', window.iconLibrary);
    
    // Update active category button
    document.querySelectorAll('.icon-category').forEach(btn => btn.classList.remove('active'));
    const categoryBtn = document.querySelector(`[data-category="${category}"]`);
    if (categoryBtn) categoryBtn.classList.add('active');
    
    const grid = document.getElementById('icon-grid');
    grid.innerHTML = '';
    
    let iconsToShow = [];
    if (category === 'all') {
        iconsToShow = [...window.iconLibrary.brands, ...window.iconLibrary.solid, ...window.iconLibrary.regular];
    } else {
        iconsToShow = window.iconLibrary[category] || [];
    }
    
    console.log('Icons to show for category', category + ':', iconsToShow.length);
    
    if (iconsToShow.length === 0) {
        // If we're in the middle of loading, show loading indicator
        if (!window.iconsLoaded) {
            showLoadingIndicator();
            return;
        }
        
        // If loading is complete but still no icons, show fallback message and force load fallback
        grid.innerHTML = '<p style="grid-column: 1 / -1; text-align: center; color: #666;">No icons found. Loading fallback icons...</p>';
        // Force load fallback if we don't have any icons
        window.iconLibrary = iconLibrary = getFallbackIcons();
        window.iconsLoaded = iconsLoaded = true;
        // Retry showing category
        setTimeout(() => showCategory(category), 100);
        return;
    }
    
    iconsToShow.forEach(iconClass => {
        const iconDiv = document.createElement('div');
        iconDiv.className = 'icon-option';
        iconDiv.style.cssText = 'padding: 15px; text-align: center; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: all 0.2s; background: white;';
        iconDiv.innerHTML = `<i class="${iconClass}" style="font-size: 24px; display: block; margin-bottom: 5px; color: #2c6e49;"></i><small style="font-size: 10px; word-break: break-all;">${iconClass}</small>`;
        
        iconDiv.addEventListener('click', () => selectIcon(iconClass));
        iconDiv.addEventListener('mouseenter', () => {
            iconDiv.style.backgroundColor = '#f0f0f0';
            iconDiv.style.borderColor = '#2c6e49';
        });
        iconDiv.addEventListener('mouseleave', () => {
            iconDiv.style.backgroundColor = 'white';
            iconDiv.style.borderColor = '#ddd';
        });
        
        grid.appendChild(iconDiv);
    });
    
    console.log('Added', iconsToShow.length, 'icons to grid');
}

// Make functions globally accessible
window.showCategory = showCategory;

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('icon-picker-modal');
    if (event.target === modal) {
        closeIconPicker();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeIconPicker();
    }
});

// Enhanced event handling for icon picker
document.addEventListener('DOMContentLoaded', function() {
    console.log('BitStream: DOM loaded, setting up icon picker...');
    
    // Initialize the new mapping preview
    updateNewMappingPreview();
    
    // Test if our function exists
    console.log('openIconPicker function exists:', typeof window.openIconPicker);
    console.log('updateNewMappingPreview function exists:', typeof window.updateNewMappingPreview);
    
    // Add click listeners to all icon picker buttons
    const iconPickerButtons = document.querySelectorAll('button[onclick*="openIconPicker"]');
    console.log('Found icon picker buttons:', iconPickerButtons.length);
    
    iconPickerButtons.forEach(function(button, index) {
        console.log('Setting up button', index, ':', button);
        
        // Add both onclick backup and direct event listener
        button.addEventListener('click', function(e) {
            console.log('=== BUTTON CLICKED ===');
            console.log('Button clicked:', this);
            console.log('Onclick attribute:', this.getAttribute('onclick'));
            
            // Extract input ID from onclick attribute
            const onclickValue = this.getAttribute('onclick');
            const match = onclickValue ? onclickValue.match(/openIconPicker\('([^']+)'\)/) : null;
            
            if (match) {
                const inputId = match[1];
                console.log('Extracted input ID:', inputId);
                
                // Call function directly
                if (typeof window.openIconPicker === 'function') {
                    window.openIconPicker(inputId);
                } else {
                    console.error('openIconPicker function not available');
                }
            } else {
                console.error('Could not extract input ID from onclick');
            }
        });
    });
});
</script>
