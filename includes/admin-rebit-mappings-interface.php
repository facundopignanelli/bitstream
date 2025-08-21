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
<style>
/* Ultra-aggressive full width for this admin page */
html, body {
    overflow-x: auto !important;
}

/* WordPress admin structure overrides */
#wpwrap, 
#adminmenuwrap + #wpcontent,
#wpcontent,
#wpbody,
#wpbody-content {
    max-width: none !important;
    width: 100% !important;
}

#wpcontent {
    margin-left: 160px !important;
}

#wpbody-content {
    padding-right: 20px !important;
    padding-left: 20px !important;
}

/* Main container overrides */
.wrap,
.wrap > *,
.postbox,
.card {
    max-width: none !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

/* Specific WordPress card class overrides */
.wp-admin .card,
.wp-admin .postbox,
.wp-admin .wrap .card,
.wp-admin #wpbody-content .card,
.wp-admin #wpbody-content .postbox {
    max-width: none !important;
    width: 100% !important;
    margin: 0 0 20px 0 !important;
}

/* Flex container overrides */
div[style*="display: flex"],
.wp-admin div[style*="display: flex"] {
    max-width: none !important;
    width: 100% !important;
}

/* Table and form overrides */
.wp-admin table,
.wp-admin .form-table,
.wp-admin .widefat {
    max-width: none !important;
    width: 100% !important;
}

/* Remove any margin constraints */
.wp-admin .wrap {
    margin: 0 !important;
    padding: 0 !important;
}

.mapping-row:hover {
    background: #f0f0f1 !important;
}
.mapping-preview {
    font-size: 14px;
    line-height: 1.4;
}
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
.icon-category {
    padding: 8px 16px;
    border: 1px solid #ddd;
    background: #f7f7f7;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.2s;
}
.icon-category:hover {
    background: #e7e7e7;
    border-color: #2c6e49;
}
.icon-category.active {
    background: #2c6e49;
    color: white;
    border-color: #2c6e49;
}
.icon-option:hover {
    background: #f0f0f0 !important;
    border-color: #2c6e49 !important;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(44, 110, 73, 0.2);
}
#icon-picker-modal {
    backdrop-filter: blur(2px);
}
#icon-grid::-webkit-scrollbar {
    width: 8px;
}
#icon-grid::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
#icon-grid::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}
#icon-grid::-webkit-scrollbar-thumb:hover {
    background: #555;
}
</style>

<div class="wrap">
    <h1>ReBit Mappings</h1>
    <p class="description">Configure how different websites appear when shared as ReBits. Each mapping adds a custom icon and label for specific domains.</p>
    
    <!-- Top Section: Quick Add and Add New Mapping -->
    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <!-- Quick Presets Section -->
        <div class="card" style="flex: 1;">
            <h2 class="title">Quick Add Popular Sites</h2>
            <p>Add pre-configured mappings for popular websites:</p>
            <form method="post" style="margin-bottom: 15px;">
                <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                <div style="display: flex; gap: 10px; align-items: end;">
                    <div style="flex: 1;">
                        <label><strong>Website:</strong></label><br>
                        <select name="preset_selection" style="width: 100%;">
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
        <div class="card" style="flex: 1;">
            <h2 class="title">Add New Mapping</h2>
            <form method="post">
                <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                <div style="margin-bottom: 15px;">
                    <label><strong>Domain:</strong></label><br>
                    <input type="text" name="bitstream_rebit_mappings[new][domain]" 
                           placeholder="example.com" style="width: 100%;" />
                    <small class="description">Enter just the domain (e.g., "twitter.com")</small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label><strong>Label:</strong></label><br>
                    <input type="text" name="bitstream_rebit_mappings[new][label]" 
                           placeholder="shared from Example" style="width: 100%;" />
                    <small class="description">Text shown when sharing from this site</small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label><strong>Icon Class:</strong></label><br>
                    <div style="position: relative;">
                        <input type="text" name="bitstream_rebit_mappings[new][icon]" 
                               placeholder="fas fa-link" style="width: 100%; padding-right: 40px;" 
                               id="new-icon-input" />
                        <button type="button" class="button" onclick="openIconPicker('new-icon-input')" 
                                style="position: absolute; right: 5px; top: 2px; height: 26px; padding: 2px 8px;">
                            <i class="fas fa-palette"></i>
                        </button>
                    </div>
                    <small class="description">Font Awesome class or use the icon picker</small>
                </div>
                <p class="submit" style="margin-top: 15px;">
                    <input type="submit" name="submit" class="button-primary" value="Add Mapping" />
                </p>
            </form>
        </div>
    </div>
    
    <!-- Current Mappings -->
    <form method="post" id="mappings-form">
        <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
        
        <div class="card">
            <h2 class="title">Current Mappings</h2>
            
            <?php if (empty($mappings)): ?>
                <p class="description">No mappings configured yet. Use the sections above to add mappings.</p>
            <?php else: ?>
                <div id="mappings-container">
                    <?php foreach ($mappings as $i => $map): ?>
                        <div class="mapping-row" style="display: flex; align-items: center; margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #fafafa;">
                            <div style="flex: 1; margin-right: 15px;">
                                <label><strong>Domain:</strong></label><br>
                                <input type="text" name="bitstream_rebit_mappings[<?php echo $i; ?>][domain]" 
                                       value="<?php echo esc_attr($map['domain']); ?>" 
                                       placeholder="example.com" style="width: 100%;" />
                            </div>
                            <div style="flex: 1; margin-right: 15px;">
                                <label><strong>Label:</strong></label><br>
                                <input type="text" name="bitstream_rebit_mappings[<?php echo $i; ?>][label]" 
                                       value="<?php echo esc_attr($map['label']); ?>" 
                                       placeholder="shared from Twitter" style="width: 100%;" />
                            </div>
                            <div style="flex: 1; margin-right: 15px;">
                                <label><strong>Icon Class:</strong></label><br>
                                <div style="position: relative;">
                                    <input type="text" name="bitstream_rebit_mappings[<?php echo $i; ?>][icon]" 
                                           value="<?php echo esc_attr($map['icon']); ?>" 
                                           placeholder="fab fa-twitter" style="width: 100%; padding-right: 40px;" 
                                           id="icon-input-<?php echo $i; ?>" />
                                    <button type="button" class="button" onclick="openIconPicker('icon-input-<?php echo $i; ?>')" 
                                            style="position: absolute; right: 5px; top: 2px; height: 26px; padding: 2px 8px;">
                                        <i class="fas fa-palette"></i>
                                    </button>
                                </div>
                            </div>
                            <div style="flex: 0 0 150px; margin-right: 15px;">
                                <label><strong>Preview:</strong></label><br>
                                <div class="mapping-preview" style="padding: 8px; border: 1px solid #ccc; border-radius: 3px; background: white; min-height: 30px;">
                                    <i class="<?php echo esc_attr($map['icon']); ?>" style="margin-right: 8px; color: #2c6e49;"></i>
                                    <span><?php echo esc_html($map['label']); ?></span>
                                </div>
                            </div>
                            <div style="flex: 0 0 auto;">
                                <button type="button" class="button button-link-delete" onclick="removeMapping(this)" style="color: #a00;">Remove</button>
                                <input type="hidden" name="bitstream_rebit_mappings[<?php echo $i; ?>][remove]" value="0" class="remove-flag" />
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="Save All Mappings" />
        </p>
    </form>
    
    <!-- Icon Picker Modal -->
    <div id="icon-picker-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; max-width: 800px; max-height: 80vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                <h3 style="margin: 0;">Select an Icon</h3>
                <button type="button" onclick="closeIconPicker()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            
            <div style="margin-bottom: 15px;">
                <input type="text" id="icon-search" placeholder="Search icons..." style="width: 100%; padding: 8px;" onkeyup="filterIcons()" />
            </div>
            
            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <button type="button" class="icon-category active" onclick="showCategory('all')" data-category="all">All</button>
                <button type="button" class="icon-category" onclick="showCategory('brands')" data-category="brands">Brands</button>
                <button type="button" class="icon-category" onclick="showCategory('solid')" data-category="solid">Solid</button>
                <button type="button" class="icon-category" onclick="showCategory('regular')" data-category="regular">Regular</button>
            </div>
            
            <div id="icon-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; max-height: 400px; overflow-y: auto;">
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

// Check if Font Awesome is available and load fallback immediately if not
function checkFontAwesome() {
    // Check if any Font Awesome CSS is loaded
    const stylesheets = Array.from(document.styleSheets);
    const hasFontAwesome = stylesheets.some(sheet => {
        try {
            return sheet.href && (sheet.href.includes('fontawesome') || sheet.href.includes('fa-'));
        } catch (e) {
            return false;
        }
    });
    
    console.log('Font Awesome CSS detected:', hasFontAwesome);
    
    if (!hasFontAwesome) {
        console.log('Font Awesome not detected, loading fallback immediately');
        window.iconLibrary = iconLibrary = getFallbackIcons();
        window.iconsLoaded = iconsLoaded = true;
        return false;
    }
    return true;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (!checkFontAwesome()) {
        console.log('Using fallback icons from start');
    }
});

// Function to dynamically extract Font Awesome icons from loaded stylesheets
function loadFontAwesomeIcons() {
    if (iconsLoaded) return Promise.resolve();
    
    return new Promise((resolve) => {
        console.log('Loading Font Awesome icons...');
        
        // Always start with fallback icons to ensure we have something to show
        iconLibrary = getFallbackIcons();
        console.log('Loaded fallback library with', iconLibrary.brands.length + iconLibrary.solid.length + iconLibrary.regular.length, 'icons');
        
        const styleSheets = document.styleSheets;
        const foundIcons = { brands: [], solid: [], regular: [] };
        let foundAnyIcons = false;
        
        try {
            for (let sheet of styleSheets) {
                try {
                    // Check if this is a Font Awesome stylesheet (local or CDN)
                    if (!sheet.href || (!sheet.href.includes('font-awesome') && !sheet.href.includes('fa'))) continue;
                    
                    console.log('Checking FA stylesheet:', sheet.href);
                    const rules = sheet.cssRules || sheet.rules;
                    if (!rules) continue;
                    
                    for (let rule of rules) {
                        if (rule.selectorText && rule.selectorText.includes('::before')) {
                            const selector = rule.selectorText;
                            
                            // Extract FA classes - handle multiple selectors
                            const matches = selector.split(',');
                            for (let match of matches) {
                                match = match.trim();
                                if (match.includes('.fab.fa-')) {
                                    const iconMatch = match.match(/\.fab\.fa-([^:,\s.]+)/);
                                    if (iconMatch) {
                                        foundIcons.brands.push('fab fa-' + iconMatch[1]);
                                        foundAnyIcons = true;
                                    }
                                } else if (match.includes('.fas.fa-')) {
                                    const iconMatch = match.match(/\.fas\.fa-([^:,\s.]+)/);
                                    if (iconMatch) {
                                        foundIcons.solid.push('fas fa-' + iconMatch[1]);
                                        foundAnyIcons = true;
                                    }
                                } else if (match.includes('.far.fa-')) {
                                    const iconMatch = match.match(/\.far\.fa-([^:,\s.]+)/);
                                    if (iconMatch) {
                                        foundIcons.regular.push('far fa-' + iconMatch[1]);
                                        foundAnyIcons = true;
                                    }
                                }
                            }
                        }
                    }
                } catch (e) {
                    // Skip inaccessible stylesheets (CORS issues)
                    console.log('Skipping stylesheet due to CORS:', sheet.href);
                    continue;
                }
            }
        } catch (e) {
            console.log('Error accessing stylesheets:', e);
        }
        
        // If we found icons from local FA, use them; otherwise keep fallback
        if (foundAnyIcons && (foundIcons.brands.length > 10 || foundIcons.solid.length > 10)) {
            window.iconLibrary = iconLibrary = foundIcons;
            console.log('Enhanced with', foundIcons.brands.length + foundIcons.solid.length + foundIcons.regular.length, 'icons from Font Awesome stylesheets');
        } else {
            console.log('Using fallback icon library');
            window.iconLibrary = iconLibrary = getFallbackIcons();
            console.log('Using fallback icon library with', iconLibrary.brands.length + iconLibrary.solid.length + iconLibrary.regular.length, 'icons');
        }
        
        // Remove duplicates and sort
        Object.keys(window.iconLibrary).forEach(category => {
            window.iconLibrary[category] = [...new Set(window.iconLibrary[category])].sort();
        });
        
        window.iconsLoaded = iconsLoaded = true;
        resolve();
    });
}

// Make loadFontAwesomeIcons globally accessible
window.loadFontAwesomeIcons = loadFontAwesomeIcons;

function closeIconPicker() {
    document.getElementById('icon-picker-modal').style.display = 'none';
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
    }
    closeIconPicker();
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
    console.log('Opening icon picker for:', inputId);
    window.currentIconInput = currentIconInput = document.getElementById(inputId);
    document.getElementById('icon-picker-modal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // If icons not loaded yet, try to load Font Awesome first, then fallback
    if (!window.iconsLoaded) {
        console.log('Attempting to load Font Awesome icons first...');
        
        // Try to load Font Awesome icons with a timeout
        Promise.race([
            loadFontAwesomeIcons(),
            new Promise((_, reject) => setTimeout(() => reject('Timeout'), 2000))
        ]).then(() => {
            console.log('Successfully loaded Font Awesome icons');
            showCategory('all');
        }).catch(e => {
            console.log('Font Awesome loading failed or timed out, using fallback:', e);
            window.iconLibrary = iconLibrary = getFallbackIcons();
            window.iconsLoaded = iconsLoaded = true;
            console.log('Fallback icons loaded:', iconLibrary.brands.length, 'brands,', iconLibrary.solid.length, 'solid,', iconLibrary.regular.length, 'regular');
            showCategory('all');
        });
    } else {
        // Icons already loaded, just show them
        showCategory('all');
    }
    
    document.getElementById('icon-search').value = '';
}

// Make functions globally accessible
window.openIconPicker = openIconPicker;

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
</script>
