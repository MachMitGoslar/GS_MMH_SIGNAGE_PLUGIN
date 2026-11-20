<?php
/**
 * Screen Template
 *
 * Frontend display template for digital signage screens.
 * Handles access control, content loading, and player initialization.
 */

// Panel preview: logged-in users may render the player without device approval
// and optionally force a channel/slide (?preview=1&channel=…&slide=…&orientation=…).
$isPreview = kirby()->user() !== null && get('preview') === '1';
$previewQuery = '';
$orientation = (string) $page->orientation();
if ($isPreview) {
    $previewQuery = '?' . http_build_query(array_filter([
        'preview' => '1',
        'channel' => get('channel'),
        'slide' => get('slide'),
    ]));
    if (in_array(get('orientation'), ['vertical', 'horizontal'], true)) {
        $orientation = get('orientation');
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $page->title() ?> - Digital Signage</title>

    <!-- Design System CSS -->
    <link rel="stylesheet" href="<?= url('/assets/css/design-system/tokens/colors.css') ?>">
    <link rel="stylesheet" href="<?= url('/assets/css/design-system/tokens/fonts.css') ?>">
    <link rel="stylesheet" href="<?= url('/assets/css/design-system/layout/grid.css') ?>">

    <!-- Signage Player Styles -->
    <link rel="stylesheet" href="<?= url('signage/assets/css/signage-player.css') ?>">
</head>
<body data-orientation="<?= $orientation ?>">

    <div id="signage-player"></div>
    <div class="loading" id="loading-indicator"></div>

    <script>
        /**
         * Signage Player
         * Client-side content rotation and access control
         */

        const SignagePlayer = {
            config: {
                screenSlug: '<?= $page->slug() ?>',
                apiBase: '<?= url('api/signage') ?>',
                onboardingUrl: '<?= url('signage') ?>',
                standbyLogoUrl: '<?= url('/assets/svg/machmit-logo.svg') ?>',
                requiresAccessControl: <?= !$isPreview && $page->whitelist_enabled()->toBool() ? 'true' : 'false' ?>,
                previewQuery: <?= json_encode($previewQuery) ?>,
                syncInterval: 1000, // Check access changes every second
            },

            state: {
                uuid: null,
                accessStatus: null,
                contentData: null,
                contentRevision: null,
                currentSlideIndex: 0,
                activeAnimation: null,
                treeCursor: {},
                treeLastPoints: {},
                slideTimeout: null,
                syncIntervalId: null,
                redirectTimeoutId: null,
            },

            /**
             * Initialize player
             */
            async init() {

                if (!this.config.requiresAccessControl) {
                    await this.loadContent();
                    this.startSync();
                    return;
                }

                // Generate or retrieve device UUID
                this.state.uuid = this.getOrCreateUUID();

                // Check access
                await this.checkAccess();

                this.startSync();
            },

            /**
             * Get or create device UUID
             */
            getOrCreateUUID() {
                let uuid = localStorage.getItem('signage_onboarding_uuid');
                if (!uuid) {
                    uuid = localStorage.getItem('signage_device_uuid');
                }

                if (!uuid) {
                    uuid = this.generateUUID();
                }

                localStorage.setItem('signage_onboarding_uuid', uuid);
                localStorage.setItem('signage_device_uuid', uuid);

                return uuid;
            },

            /**
             * Generate UUID v4
             */
            generateUUID() {
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    const r = Math.random() * 16 | 0;
                    const v = c == 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
            },

            /**
             * Check access with server
             */
            async checkAccess() {
                try {
                    this.showLoading(true);

                    const response = await fetch(this.config.apiBase + '/check-access', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            screen: this.config.screenSlug,
                            uuid: this.state.uuid,
                        }),
                    });

                    const data = await response.json();
                    this.state.accessStatus = data.access;


                    if (data.access === 'granted') {
                        if (data.message && data.message !== 'Device approved') {
                            this.redirectToOnboarding(300);
                            return;
                        }
                        await this.syncGrantedContent();
                    } else if (data.access === 'pending') {
                        this.showAccessPending();
                    } else {
                        this.showAccessDenied(data.message);
                    }

                } catch (error) {
                    console.error('❌ Access check failed:', error);
                    this.showError('Failed to check access');
                } finally {
                    this.showLoading(false);
                }
            },

            /**
             * Load content data
             */
            async loadContent() {
                try {
                    const response = await fetch(`${this.config.apiBase}/content/${this.config.screenSlug}${this.config.previewQuery}`);
                    const data = await response.json();

                    this.state.contentData = data;
                    this.state.contentRevision = data.revision || null;

                    if (data.status === 'active') {
                        this.renderSlides(data.slides);
                        this.startRotation();
                    } else if (data.status === 'standby') {
                        this.showStandby(data);
                    } else {
                        this.showError(data.message || 'No content available');
                    }

                } catch (error) {
                    console.error('❌ Content load failed:', error);
                    this.showError('Failed to load content');
                }
            },

            async syncGrantedContent() {
                if (!this.state.contentData) {
                    await this.loadContent();
                    return;
                }

                try {
                    const response = await fetch(`${this.config.apiBase}/content-state/${this.config.screenSlug}${this.config.previewQuery}`);
                    const data = await response.json();

                    if (!data.revision || data.revision !== this.state.contentRevision) {
                        await this.loadContent();
                    }
                } catch (error) {
                    console.error('❌ Content state check failed:', error);
                }
            },

            startSync() {
                if (this.state.syncIntervalId) {
                    return;
                }

                const syncAction = this.config.requiresAccessControl
                    ? () => this.checkAccess()
                    : () => this.syncGrantedContent();

                this.state.syncIntervalId = setInterval(syncAction, this.config.syncInterval);
            },
            stopSync() {
                if (!this.state.syncIntervalId) {
                    return;
                }

                clearInterval(this.state.syncIntervalId);
                this.state.syncIntervalId = null;
            },
            redirectToOnboarding(delay = 1200) {
                if (this.state.redirectTimeoutId) {
                    return;
                }

                this.state.redirectTimeoutId = window.setTimeout(() => {
                    window.location.href = this.config.onboardingUrl;
                }, delay);
            },

            /**
             * Render slides
             */
            renderSlides(slides) {
                const player = document.getElementById('signage-player');
                player.innerHTML = '';
                player.style.backgroundColor = this.state.contentData.channel.background.background_color || '#000000';
                slides.forEach((slide, index) => {
                    const slideEl = document.createElement('div');
                    const contentPosition = slide.content_position || 'center';
                    const effectiveBackground = this.getEffectiveBackground(slide);
                    slideEl.className = `signage-slide content-position-${contentPosition}`;
                    slideEl.dataset.transition = slide.transition || 'fade';
                    slideEl.dataset.duration = slide.duration;
                    slideEl.dataset.contentPosition = contentPosition;
                    slideEl.dataset.slideId = slide.id || String(index);
                    slideEl.style.transitionDuration = `${slide.transition_duration || 1}s`;

                    // Apply background
                    this.applyBackground(slideEl, effectiveBackground);
                    this.applyTextTheme(slideEl, effectiveBackground);

                    // Render content
                    const contentWrapper = document.createElement('div');
                    contentWrapper.className = slide.type === 'template'
                        ? 'slide-content slide-content--template'
                        : slide.type === 'christmas_tree'
                            ? 'slide-content slide-content--tree'
                            : 'slide-content';
                    contentWrapper.innerHTML = this.renderSlideContent(slide);
                    if (slide.type === 'christmas_tree') {
                        slideEl.treeData = slide.christmas_tree || null;
                        // a photo/video background (e.g. the MMH interior) shows through a tinted glass layer
                        contentWrapper.querySelector('.tree-slide')?.classList.toggle(
                            'tree-slide--photo',
                            ['image', 'video'].includes(effectiveBackground?.type)
                        );
                    }
                    
                    slideEl.appendChild(contentWrapper);
                    player.appendChild(slideEl);
                });

            },

            getEffectiveBackground(slide) {
                const slideBackground = slide?.background || null;

                if (slideBackground && slideBackground.type && slideBackground.type !== 'none') {
                    return slideBackground;
                }

                if (slide?.type === 'christmas_tree') {
                    return { type: 'color', color: '#0b1430', overlay: { enabled: false } };
                }

                if (slide?.type === 'template' && slide.template?.image?.url) {
                    const themeOverlays = {
                        light: { color: '#f4dfbd', opacity: 66 },
                        dark: { color: '#10100f', opacity: 58 },
                    };
                    const overlay = themeOverlays[slide.template.theme] || themeOverlays.light;

                    return {
                        type: 'image',
                        image: {
                            url: slide.template.image.url,
                            position: slide.template.image.position || 'center',
                            size: 'cover',
                        },
                        overlay: {
                            enabled: true,
                            color: overlay.color,
                            opacity: overlay.opacity,
                            gradient: 'none',
                        },
                    };
                }

                // Slides from a combined ("add") channel bring that channel's
                // default background along.
                return slide?.channel_background
                    || this.state.contentData?.channel?.background
                    || slideBackground;
            },

            applyTextTheme(slideEl, background) {
                const theme = this.getTextTheme(background);
                slideEl.style.setProperty('--signage-text-color', theme.text);
                slideEl.style.setProperty('--signage-muted-color', theme.muted);
                slideEl.style.setProperty('--signage-surface-color', theme.surface);
                slideEl.style.setProperty('--signage-surface-strong-color', theme.surfaceStrong);
                slideEl.style.setProperty('--signage-border-color', theme.border);
                slideEl.style.setProperty('--signage-shadow-color', theme.shadow);
                slideEl.style.setProperty('--signage-accent-surface-color', theme.accentSurface);
            },

            getTextTheme(background) {
                const lightTheme = {
                    text: '#000000',
                    muted: '#4e4e4d',
                    surface: '#ffffff',
                    surfaceStrong: '#ffffff',
                    border: 'rgb(0 0 0 / 10%)',
                    shadow: 'rgb(0 0 0 / 10%)',
                    accentSurface: 'rgba(252, 206, 76, 0.18)',
                };

                const darkTheme = {
                    text: '#ffffff',
                    muted: '#d9d9d9',
                    surface: 'rgba(255, 255, 255, 0.08)',
                    surfaceStrong: 'rgba(255, 255, 255, 0.12)',
                    border: 'rgba(255, 255, 255, 0.12)',
                    shadow: 'rgba(0, 0, 0, 0.18)',
                    accentSurface: 'rgba(252, 206, 76, 0.12)',
                };

                if (!background) {
                    return lightTheme;
                }

                const color = background.color || background.background_color || background.overlay?.color || null;
                if (!color) {
                    return lightTheme;
                }

                const normalized = this.normalizeHexColor(color);
                if (!normalized) {
                    return lightTheme;
                }

                const brightness = this.getColorBrightness(normalized);
                return brightness >= 155 ? lightTheme : darkTheme;
            },

            normalizeHexColor(color) {
                if (typeof color !== 'string' || !color.startsWith('#')) {
                    return null;
                }

                let hex = color.slice(1);
                if (hex.length === 3 || hex.length === 4) {
                    hex = hex.split('').slice(0, 3).map((char) => char + char).join('');
                } else if (hex.length >= 6) {
                    hex = hex.slice(0, 6);
                }

                return /^[0-9a-fA-F]{6}$/.test(hex) ? `#${hex}` : null;
            },

            getColorBrightness(color) {
                const r = parseInt(color.slice(1, 3), 16);
                const g = parseInt(color.slice(3, 5), 16);
                const b = parseInt(color.slice(5, 7), 16);
                return (r * 299 + g * 587 + b * 114) / 1000;
            },

            /**
             * Apply background to slide element
             */
            applyBackground(slideEl, background) {
                if (!background || background.type === 'none') {
                    return;
                }

                if (background.type === 'image' && background.image) {
                    slideEl.style.backgroundImage = `url('${background.image.url}')`;
                    slideEl.style.backgroundPosition = background.image.position || '50% 50%';
                    slideEl.style.backgroundSize = background.image.size || 'cover';
                    slideEl.style.backgroundRepeat = 'no-repeat';
                } else if (background.type === 'video' && background.video) {
                    // Create background video element
                    const videoEl = document.createElement('video');
                    videoEl.className = 'bg-video';
                    videoEl.autoplay = true;
                    videoEl.muted = true;
                    videoEl.loop = true;
                    videoEl.playsInline = true;
                    
                    const source = document.createElement('source');
                    source.src = background.video.url;
                    source.type = background.video.type;
                    videoEl.appendChild(source);
                    
                    slideEl.appendChild(videoEl);
                } else if (background.type === 'color' && background.color) {
                    slideEl.style.backgroundColor = background.color;
                }

                // Apply overlay
                if (background.overlay && background.overlay.enabled) {
                    const overlay = document.createElement('div');
                    overlay.className = 'slide-overlay';
                    overlay.style.background = this.buildOverlayBackground(background.overlay);
                    slideEl.appendChild(overlay);
                }
            },

            /**
             * CSS background for a slide overlay.
             *
             * Linear directions follow the panel labels: "to-top" („Nach oben
             * verblassen") keeps the full color at the bottom and fades out
             * towards the top. `strength` (10–100) is how far the color reaches
             * into the image before it is fully transparent.
             */
            buildOverlayBackground(overlay) {
                const opacity = Math.max(0, Math.min(1, Number(overlay.opacity || 0) / 100));
                const color = this.normalizeHexColor(overlay.color) || '#000000';
                const gradient = overlay.gradient || 'none';
                const rawStrength = Number(overlay.strength);
                const strength = Number.isFinite(rawStrength) && rawStrength > 0
                    ? Math.max(10, Math.min(100, rawStrength))
                    : 100;

                const full = this.hexToRgba(color, opacity);
                const half = this.hexToRgba(color, opacity * 0.5);
                const clear = this.hexToRgba(color, 0);

                if (gradient === 'radial') {
                    // Transparent centre, the edge reaches `strength` % inwards.
                    const inner = 100 - strength;
                    const middle = 100 - strength / 2;

                    return `radial-gradient(ellipse farthest-corner at center, ${clear} ${inner}%, ${half} ${middle}%, ${full} 100%)`;
                }

                const directions = {
                    'to-top': 'to top',
                    'to-bottom': 'to bottom',
                    'to-left': 'to left',
                    'to-right': 'to right',
                };

                if (directions[gradient]) {
                    return `linear-gradient(${directions[gradient]}, ${full} 0%, ${half} ${strength / 2}%, ${clear} ${strength}%)`;
                }

                return full;
            },

            /**
             * Convert hex color to rgba with opacity
             */
            hexToRgba(hex, alpha) {
                const r = parseInt(hex.slice(1, 3), 16);
                const g = parseInt(hex.slice(3, 5), 16);
                const b = parseInt(hex.slice(5, 7), 16);
                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
            },

            /**
             * Render individual slide content
             */
            renderSlideContent(slide) {
              switch (slide.type) {
                case 'blocks':
                  return this.renderLayoutSlide(slide.layout);
                case 'template':
                  return this.renderTemplateSlide(slide.template);
                case 'video':
                  return this.renderVideoSlide(slide.video);
                case 'calendar':
                  return this.renderCalendarSlide(slide.calendar);
                case 'canva':
                  return this.renderCanvaSlide(slide.canva);
                case 'christmas_tree':
                  return this.renderChristmasTreeSlide(slide.christmas_tree);
                default:
                  return '<p>Unknown slide type</p>';
              }
            },

            /**
             * Render layout-based slide (with rows and columns)
             */
            renderLayoutSlide(layout) {
                if (!layout || layout.length === 0) {
                    return '<p>No content</p>';
                }

                return layout.map(row => {
                    const verticalAlign = row.settings?.vertical_align || 'center';
                    const padding = row.settings?.padding || 'medium';
                    
                    const columnsHtml = row.columns.map(column => {
                        const blocksHtml = column.blocks.map(block => this.renderBlock(block)).join('');
                        return `
                            <div class="layout-column" data-width="${column.width}">
                                ${blocksHtml}
                            </div>
                        `;
                    }).join('');

                    return `
                        <div class="layout-row" 
                             data-vertical-align="${verticalAlign}" 
                             data-padding="${padding}">
                            ${columnsHtml}
                        </div>
                    `;
                }).join('');
            },

            /**
             * Render predefined template slide
             */
            renderTemplateSlide(template) {
                const preset = template?.preset || 'event_focus';
                const theme = template?.theme || 'light';
                const title = this.escapeHtml(template?.title || '');
                const subtitle = this.escapeHtml(template?.subtitle || '');
                const label = this.escapeHtml(template?.label || '');
                const message = this.escapeHtml(template?.message || '');
                const highlight = this.escapeHtml(template?.highlight || '');
                const footer = this.escapeHtml(template?.footer || '');
                const date = this.formatTemplateDate(template?.date || '');
                const shortDate = this.formatTemplateDateShort(template?.date || '');
                const time = this.escapeHtml(template?.time || '');
                const timeLabel = this.formatTemplateTimeRange(template?.time || '', template?.end_time || '');
                const location = this.escapeHtml(template?.location || '');
                const recurrence = this.escapeHtml(template?.recurrence || '');
                const hasMeta = Boolean(date || time || location);

                if (preset === 'event_focus') {
                    const eventDate = shortDate || date;
                    const eventTime = timeLabel || time;
                    const whenLabel = recurrence ? 'Wann' : 'Datum';
                    const whenValue = recurrence
                        ? `${recurrence}${eventDate ? `<small class="template-slide__date-note">ab ${eventDate}</small>` : ''}`
                        : eventDate;

                    return `
                        <article class="template-slide template-slide--event_focus template-slide--${theme}">
                            <div class="template-slide__body">
                                ${title ? `<h1 class="template-slide__title">${title}</h1>` : ''}
                                ${subtitle ? `<p class="template-slide__subtitle">${subtitle}</p>` : ''}
                            </div>
                            ${whenValue || eventTime || location ? `
                                <dl class="template-slide__date-card">
                                    ${whenValue ? `
                                        <div class="template-slide__date-item">
                                            <dt>${whenLabel}</dt>
                                            <dd>${whenValue}</dd>
                                        </div>
                                    ` : ''}
                                    ${eventTime ? `
                                        <div class="template-slide__date-item">
                                            <dt>Zeit</dt>
                                            <dd>${eventTime}</dd>
                                        </div>
                                    ` : ''}
                                    ${location ? `
                                        <div class="template-slide__date-item">
                                            <dt>Ort</dt>
                                            <dd>${location}</dd>
                                        </div>
                                    ` : ''}
                                </dl>
                            ` : ''}
                        </article>
                    `;
                }

                if (preset === 'announcement') {
                    return `
                        <article class="template-slide template-slide--announcement template-slide--${theme}">
                            <div class="template-slide__announcement">
                                ${label ? `<p class="template-slide__label">${label}</p>` : ''}
                                ${title ? `<h1 class="template-slide__title">${title}</h1>` : ''}
                                ${message ? `<p class="template-slide__message">${message}</p>` : ''}
                                ${footer ? `<p class="template-slide__footer">${footer}</p>` : ''}
                            </div>
                        </article>
                    `;
                }

                if (preset === 'compact_info') {
                    return `
                        <article class="template-slide template-slide--compact_info template-slide--${theme}">
                            <div class="template-slide__compact">
                                ${label ? `<p class="template-slide__label">${label}</p>` : ''}
                                ${highlight ? `<p class="template-slide__highlight">${highlight}</p>` : ''}
                                ${title ? `<h1 class="template-slide__title">${title}</h1>` : ''}
                                ${message ? `<p class="template-slide__message">${message}</p>` : ''}
                                ${footer ? `<p class="template-slide__footer">${footer}</p>` : ''}
                            </div>
                        </article>
                    `;
                }

                return `
                    <article class="template-slide template-slide--${preset} template-slide--${theme}">
                        <div class="template-slide__body">
                            ${title ? `<h1 class="template-slide__title">${title}</h1>` : ''}
                            ${subtitle ? `<p class="template-slide__subtitle">${subtitle}</p>` : ''}
                        </div>
                        ${hasMeta ? `
                            <dl class="template-slide__meta">
                                ${date ? `
                                    <div class="template-slide__meta-item">
                                        <dt>Datum</dt>
                                        <dd>${date}</dd>
                                    </div>
                                ` : ''}
                                ${time ? `
                                    <div class="template-slide__meta-item">
                                        <dt>Uhrzeit</dt>
                                        <dd>${time}</dd>
                                    </div>
                                ` : ''}
                                ${location ? `
                                    <div class="template-slide__meta-item">
                                        <dt>Ort</dt>
                                        <dd>${location}</dd>
                                    </div>
                                ` : ''}
                            </dl>
                        ` : ''}
                    </article>
                `;
            },

            getBlockText(block) {
                if (!block || block.text == null) {
                    return '';
                }

                return typeof block.text === 'string'
                    ? block.text
                    : (block.text.value || '');
            },

            escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            },

            formatTemplateDate(value) {
                if (!value) {
                    return '';
                }

                const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                const parsed = match
                    ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
                    : new Date(value);

                if (Number.isNaN(parsed.getTime())) {
                    return this.escapeHtml(value);
                }

                return new Intl.DateTimeFormat('de-DE', {
                    weekday: 'long',
                    day: '2-digit',
                    month: 'long',
                    year: 'numeric',
                    timeZone: 'Europe/Berlin',
                }).format(parsed);
            },

            formatTemplateDateShort(value) {
                if (!value) {
                    return '';
                }

                const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                const parsed = match
                    ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
                    : new Date(value);

                if (Number.isNaN(parsed.getTime())) {
                    return this.escapeHtml(value);
                }

                return new Intl.DateTimeFormat('de-DE', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    timeZone: 'Europe/Berlin',
                }).format(parsed);
            },

            formatTemplateTimeLabel(value) {
                if (!value) {
                    return '';
                }

                const match = String(value).match(/^(\d{1,2}):(\d{2})/);
                if (!match) {
                    return this.escapeHtml(value);
                }

                const hours = String(Number(match[1]));
                const minutes = match[2];

                return `${hours}:${minutes}`;
            },

            formatTemplateTimeRange(start, end) {
                const startLabel = this.formatTemplateTimeLabel(start);
                const endLabel = this.formatTemplateTimeLabel(end);

                if (startLabel && endLabel) {
                    return `${startLabel} bis ${endLabel}`;
                }

                return startLabel || endLabel;
            },

            formatEventDate(eventOrDate) {
                const event = typeof eventOrDate === 'object' && eventOrDate !== null
                    ? eventOrDate
                    : { date: eventOrDate };

                if (!event.date && !event.start_timestamp) {
                    return 'Kein Datum';
                }

                const parsed = event.start_timestamp
                    ? new Date(Number(event.start_timestamp) * 1000)
                    : (() => {
                        const match = String(event.date).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                        return match
                            ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
                            : new Date(event.date);
                    })();
                if (Number.isNaN(parsed.getTime())) {
                    return event.date || 'Kein Datum';
                }

                return new Intl.DateTimeFormat('de-DE', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    timeZone: 'Europe/Berlin',
                }).format(parsed);
            },

            formatEventTimeRange(event) {
                if (!event.time) {
                    return '';
                }

                return event.end_time ? `${event.time} - ${event.end_time}` : event.time;
            },

            /**
             * Render individual block
             */
            renderBlock(block) {
                switch (block.type) {
                    case 'signage-heading':
                        return `<${block.level || 'h2'}>${this.getBlockText(block)}</${block.level || 'h2'}>`;
                    
                    case 'signage-text':
                        return `<div class="text-block">${this.getBlockText(block)}</div>`;
                    
                    case 'image':
                        if (!block.src) return '';
                        return `
                            <figure class="image-block">
                                <img src="${block.src}" alt="${block.alt || ''}">
                                ${block.caption ? `<figcaption>${block.caption}</figcaption>` : ''}
                            </figure>
                        `;
                    
                    case 'list':
                        return `<div class="list-block">${this.getBlockText(block)}</div>`;
                    
                    case 'quote':
                        return `
                            <blockquote class="quote-block">
                                ${this.getBlockText(block)}
                                ${block.citation ? `<cite>${block.citation}</cite>` : ''}
                            </blockquote>
                        `;
                    
                    case 'line':
                        return '<hr class="line-block">';
                    
                    default:
                        return '';
                }
            },
            /**
             * Christmas tree slide: approved greetings hang as baubles.
             * Hang points are in SVG viewBox coordinates (0 0 600 800); each round
             * picks random free points, so the baubles "move" between rounds.
             */
            treeHangPoints: [
                { x: 240, y: 250 }, { x: 300, y: 238 }, { x: 360, y: 250 },
                { x: 250, y: 312 }, { x: 350, y: 312 },
                { x: 185, y: 382 }, { x: 260, y: 372 }, { x: 340, y: 372 }, { x: 415, y: 382 },
                { x: 215, y: 440 }, { x: 300, y: 450 }, { x: 385, y: 440 },
                { x: 135, y: 508 }, { x: 215, y: 498 }, { x: 300, y: 505 }, { x: 385, y: 498 }, { x: 465, y: 508 },
                { x: 150, y: 590 }, { x: 235, y: 580 }, { x: 320, y: 592 }, { x: 405, y: 582 }, { x: 455, y: 598 },
            ],

            treeBallColors: ['#ec665c', '#fcce4c', '#738de0', '#bb7abc', '#f1f1f1', '#ef8a5b'],

            // Light garlands as quadratic curves [start, control, end]
            treeGarlands: [
                [[232, 205], [300, 238], [368, 200]],
                [[196, 330], [300, 372], [408, 322]],
                [[150, 465], [300, 520], [452, 455]],
                [[104, 612], [300, 680], [500, 600]],
            ],

            /**
             * Deterministic pseudo random number in [0, 1) – keeps the low-poly facets stable
             */
            treeNoise(seed) {
                const value = Math.sin(seed * 127.1 + 311.7) * 43758.5453;
                return value - Math.floor(value);
            },

            treeGreens: ['#6aae74', '#57a065', '#489158', '#3c814c', '#317142', '#276237', '#1e522d', '#16431f'],

            /**
             * Low-poly tier: the tier triangle is split into a jittered triangle grid,
             * each facet gets a green by its position (light from top left) plus noise.
             */
            treeTierMarkup(apexY, baseY, halfWidth, tier) {
                const rows = tier === 1 ? 3 : 4;
                const points = [];

                for (let row = 0; row <= rows; row++) {
                    const y = apexY + ((baseY - apexY) * row) / rows;
                    const rowHalf = (halfWidth * row) / rows;
                    const line = [];

                    for (let i = 0; i <= row; i++) {
                        let x = row === 0 ? 300 : 300 - rowHalf + (2 * rowHalf * i) / row;
                        let py = y;
                        const isEdge = i === 0 || i === row;
                        if (!isEdge) {
                            x += (this.treeNoise(tier * 97 + row * 13 + i) - 0.5) * (rowHalf / row) * 0.7;
                        }
                        if (row > 0 && row < rows && !isEdge) {
                            py += (this.treeNoise(tier * 53 + row * 7 + i * 3) - 0.5) * ((baseY - apexY) / rows) * 0.45;
                        }
                        line.push([x, py]);
                    }
                    points.push(line);
                }

                const facets = [];
                const facet = (a, b, c, seed) => {
                    const cx = (a[0] + b[0] + c[0]) / 3;
                    const cy = (a[1] + b[1] + c[1]) / 3;
                    const side = (cx - 300) / halfWidth; // -1 (left, lit) … 1 (right, shade)
                    const depth = (cy - apexY) / (baseY - apexY);
                    const shade = 2.4 + side * 2.8 + depth * 0.9 + (this.treeNoise(seed) - 0.5) * 3.2;
                    const color = this.treeGreens[Math.max(0, Math.min(this.treeGreens.length - 1, Math.round(shade)))];
                    const pts = [a, b, c].map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
                    facets.push(`<polygon points="${pts}" fill="${color}" stroke="${color}"></polygon>`);
                };

                for (let row = 0; row < rows; row++) {
                    for (let i = 0; i <= row; i++) {
                        facet(points[row][i], points[row + 1][i], points[row + 1][i + 1], tier * 31 + row * 11 + i);
                        if (i < row) {
                            facet(points[row][i], points[row][i + 1], points[row + 1][i + 1], tier * 41 + row * 17 + i);
                        }
                    }
                }

                return `<g class="tree-tier">${facets.join('')}</g>`;
            },

            /**
             * Low-poly star: triangles from the centre, alternating light and dark gold
             */
            treeStarMarkup(cx, cy, outer, inner) {
                const corners = Array.from({ length: 10 }, (_, k) => {
                    const angle = -Math.PI / 2 + (k * Math.PI) / 5;
                    const radius = k % 2 === 0 ? outer : inner;
                    return [cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius];
                });

                return corners.map((point, k) => {
                    const next = corners[(k + 1) % 10];
                    const color = k % 2 === 0 ? '#fde7a5' : '#eab51d';
                    return `<polygon points="${cx},${cy} ${point[0].toFixed(1)},${point[1].toFixed(1)} ${next[0].toFixed(1)},${next[1].toFixed(1)}" fill="${color}" stroke="${color}"></polygon>`;
                }).join('');
            },

            /**
             * Faceted bauble (octagon "gem"); facets are lit from the top left
             */
            treeBaubleFacets(centerY, radius) {
                const sides = 8;
                const core = [-radius * 0.28, centerY - radius * 0.3];
                const corners = Array.from({ length: sides }, (_, k) => {
                    const angle = (k * 2 * Math.PI) / sides + Math.PI / sides;
                    return [Math.cos(angle) * radius, centerY + Math.sin(angle) * radius];
                });
                const light = Math.atan2(-0.75, -0.65);

                const outline = corners.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
                const facets = corners.map((point, k) => {
                    const next = corners[(k + 1) % sides];
                    const mid = Math.atan2((point[1] + next[1]) / 2 - centerY, (point[0] + next[0]) / 2);
                    const lit = Math.cos(mid - light);
                    const fill = lit >= 0 ? '#ffffff' : '#000000';
                    const opacity = lit >= 0 ? lit * 0.42 : -lit * 0.34;
                    return `<polygon points="${core[0].toFixed(1)},${core[1].toFixed(1)} ${point[0].toFixed(1)},${point[1].toFixed(1)} ${next[0].toFixed(1)},${next[1].toFixed(1)}" fill="${fill}" fill-opacity="${opacity.toFixed(2)}"></polygon>`;
                }).join('');

                return { outline, facets };
            },

            treeGarlandMarkup() {
                return this.treeGarlands.map(([[x0, y0], [cx, cy], [x1, y1]], row) => {
                    const lights = [];
                    for (let step = 0; step <= 8; step++) {
                        const t = step / 8;
                        const x = (1 - t) ** 2 * x0 + 2 * (1 - t) * t * cx + t ** 2 * x1;
                        const y = (1 - t) ** 2 * y0 + 2 * (1 - t) * t * cy + t ** 2 * y1;
                        lights.push(`<circle class="tree-light" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="4.5" style="animation-delay:${((step * 3 + row) % 7) * 0.4}s"></circle>`);
                    }

                    return `<path class="tree-garland" d="M${x0} ${y0} Q${cx} ${cy} ${x1} ${y1}"></path>${lights.join('')}`;
                }).join('');
            },

            renderChristmasTreeSlide(tree) {
                const title = this.escapeHtml(tree?.title || 'Frohe Weihnachten!');
                const subtitle = this.escapeHtml(tree?.subtitle || '');
                const qrLabel = this.escapeHtml(tree?.qr_label || 'Schick uns deinen Gruß!');
                const count = (tree?.greetings || []).length;
                const countText = count === 0
                    ? 'Noch hängt hier kein Gruß – sei die erste Kugel am Baum!'
                    : `${count} ${count === 1 ? 'Gruß hängt' : 'Grüße hängen'} schon am Baum`;
                // QR code SVG is generated server-side by Kirby from the panel link
                const qr = typeof tree?.qr === 'string' && tree.qr.startsWith('<svg') ? tree.qr : '';

                return `
                    <div class="tree-slide${count === 0 ? ' tree-slide--empty' : ''}">
                        <div class="tree-slide__glow" aria-hidden="true"></div>
                        <div class="tree-slide__snow" aria-hidden="true"></div>
                        <div class="tree-slide__stage">
                            <svg class="tree-slide__svg" viewBox="0 0 600 800" role="img" aria-label="Weihnachtsbaum mit ${count} Grüßen">
                                <defs>
                                    <filter id="tree-glow" x="-200%" y="-200%" width="500%" height="500%">
                                        <feGaussianBlur stdDeviation="5" result="blur"></feGaussianBlur>
                                        <feMerge><feMergeNode in="blur"></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge>
                                    </filter>
                                </defs>
                                <ellipse class="tree-slide__ground" cx="300" cy="752" rx="230" ry="18"></ellipse>
                                <polygon class="tree-slide__trunk tree-slide__trunk--light" points="300,672 282,676 284,752 300,752"></polygon>
                                <polygon class="tree-slide__trunk tree-slide__trunk--dark" points="300,672 318,676 316,752 300,752"></polygon>
                                ${this.treeTierMarkup(410, 670, 255, 4)}
                                ${this.treeTierMarkup(300, 530, 210, 3)}
                                ${this.treeTierMarkup(200, 400, 160, 2)}
                                ${this.treeTierMarkup(110, 270, 105, 1)}
                                <g class="tree-slide__lights" filter="url(#tree-glow)">${this.treeGarlandMarkup()}</g>
                                <g class="tree-slide__star" filter="url(#tree-glow)">${this.treeStarMarkup(300, 94, 38, 16)}</g>
                                <g class="tree-slide__balls"></g>
                            </svg>
                        </div>
                        <div class="tree-slide__aside">
                            <header class="tree-slide__header">
                                <p class="tree-slide__eyebrow">Weihnachtsgrüße</p>
                                <h1 class="tree-slide__title">${title}</h1>
                                ${subtitle ? `<p class="tree-slide__subtitle">${subtitle}</p>` : ''}
                            </header>
                            <figure class="tree-card" aria-live="polite">
                                <span class="tree-card__mark" aria-hidden="true">“</span>
                                <blockquote class="tree-card__text"></blockquote>
                                <figcaption class="tree-card__name"></figcaption>
                            </figure>
                            <div class="tree-progress" aria-hidden="true"></div>
                            <footer class="tree-slide__footer">
                                ${qr ? `<div class="tree-qr">${qr}</div>` : ''}
                                <div class="tree-slide__cta">
                                    ${qr ? `<p class="tree-slide__cta-title">${qrLabel}</p><p class="tree-slide__cta-text">Code scannen und mitmachen</p>` : ''}
                                    <p class="tree-slide__count">${countText}</p>
                                </div>
                            </footer>
                        </div>
                    </div>
                `;
            },

            treeBallMarkup(greeting, point, color, index) {
                const stringLength = 22 + (index % 3) * 9;
                const radius = 25;
                const centerY = stringLength + radius;
                const gem = this.treeBaubleFacets(centerY, radius);
                const initial = this.escapeHtml((greeting.name || '?').trim().charAt(0).toUpperCase());

                return `
                    <g class="tree-ball" transform="translate(${point.x} ${point.y})" data-index="${index}" style="--ball-color:${color}">
                        <g class="tree-ball__drop">
                            <g class="tree-ball__swing" style="animation-delay:-${(index * 0.7).toFixed(1)}s">
                                <line class="tree-ball__string" x1="0" y1="0" x2="0" y2="${stringLength}"></line>
                                <g class="tree-ball__orb" style="transform-origin:0px ${centerY}px">
                                    <circle class="tree-ball__halo" cx="0" cy="${centerY}" r="${radius + 10}"></circle>
                                    <rect class="tree-ball__cap" x="-6" y="${stringLength - 4}" width="12" height="8" rx="2"></rect>
                                    <polygon class="tree-ball__body" points="${gem.outline}" fill="${color}"></polygon>
                                    ${gem.facets}
                                    <text class="tree-ball__initial" x="0" y="${centerY}" dy="0.35em">${initial}</text>
                                </g>
                            </g>
                        </g>
                    </g>
                `;
            },

            /**
             * Runs one round on the tree: baubles swing in at random points,
             * each greeting is shown in turn, then the baubles are taken off again
             * so the next round can hang the next greetings elsewhere.
             * Returns a handle with cancel() (stored in state.activeAnimation).
             */
            startChristmasTree(slideEl, configuredDuration) {
                const tree = slideEl.treeData || {};
                const greetings = tree.greetings || [];
                const layer = slideEl.querySelector('.tree-slide__balls');
                const card = slideEl.querySelector('.tree-card');
                const progress = slideEl.querySelector('.tree-progress');
                const timers = [];
                const animations = [];
                const handle = {
                    cancel() {
                        timers.forEach((timer) => clearTimeout(timer));
                        animations.forEach((animation) => animation.cancel());
                    },
                };

                if (!layer || !card) {
                    return handle;
                }

                layer.innerHTML = '';
                card.classList.remove('is-visible');

                if (greetings.length === 0) {
                    return handle;
                }

                const slideId = slideEl.dataset.slideId;
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const count = Math.min(Math.max(1, tree.visible_balls || 6), greetings.length, this.treeHangPoints.length);
                const cursor = (this.state.treeCursor[slideId] || 0) % greetings.length;
                const batch = Array.from({ length: count }, (_, i) => greetings[(cursor + i) % greetings.length]);
                const colorOf = (i) => this.treeBallColors[(cursor + i) % this.treeBallColors.length];
                this.state.treeCursor[slideId] = (cursor + count) % greetings.length;

                const points = this.pickTreePoints(count, this.state.treeLastPoints[slideId] || []);
                this.state.treeLastPoints[slideId] = points;

                layer.innerHTML = batch
                    .map((greeting, i) => this.treeBallMarkup(greeting, this.treeHangPoints[points[i]], colorOf(i), i))
                    .join('');
                if (progress) {
                    progress.innerHTML = batch.map(() => '<span><i></i></span>').join('');
                }

                const balls = Array.from(layer.querySelectorAll('.tree-ball'));
                const dots = progress ? Array.from(progress.children) : [];
                const animate = (el, frames, options) => {
                    const animation = el.animate(frames, options);
                    animations.push(animation);
                    return animation;
                };

                // 1) swing in
                const introMs = reducedMotion ? 600 : 1400;
                balls.forEach((ball, i) => {
                    const drop = ball.querySelector('.tree-ball__drop');
                    animate(drop, reducedMotion
                        ? [{ opacity: 0 }, { opacity: 1 }]
                        : [
                            { transform: 'translateY(-260px)', opacity: 0 },
                            { transform: 'translateY(12px)', opacity: 1, offset: 0.7 },
                            { transform: 'translateY(0)', opacity: 1 },
                        ], {
                        duration: introMs - 300,
                        delay: i * 70,
                        easing: 'cubic-bezier(.25,.8,.35,1)',
                        fill: 'backwards',
                    });
                });

                // 2) show greetings one after another
                const outroMs = reducedMotion ? 500 : 1100;
                const available = Math.max(configuredDuration - introMs - outroMs, count * 2500);
                const perGreeting = Math.min((tree.seconds_per_greeting || 6) * 1000, available / count);
                const textEl = card.querySelector('.tree-card__text');
                const nameEl = card.querySelector('.tree-card__name');

                batch.forEach((greeting, i) => {
                    timers.push(setTimeout(() => {
                        balls.forEach((ball) => ball.classList.toggle('is-active', ball === balls[i]));
                        dots.forEach((dot, d) => dot.classList.toggle('is-done', d < i));
                        if (dots[i]) {
                            progress.style.setProperty('--tree-progress-accent', colorOf(i));
                            animate(dots[i].firstElementChild, [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], {
                                duration: perGreeting,
                                easing: 'linear',
                                fill: 'forwards',
                            });
                        }
                        card.classList.remove('is-visible');
                        timers.push(setTimeout(() => {
                            textEl.innerHTML = this.escapeHtml(greeting.text).replace(/\n/g, '<br>');
                            nameEl.textContent = greeting.name || '';
                            card.style.setProperty('--tree-card-accent', colorOf(i));
                            card.classList.toggle('tree-card--long', (greeting.text || '').length > 90);
                            card.classList.add('is-visible');
                        }, 250));
                    }, introMs + i * perGreeting));
                });

                // 3) take the baubles off again (re-hang in the next round)
                timers.push(setTimeout(() => {
                    card.classList.remove('is-visible');
                    balls.forEach((ball, i) => {
                        ball.classList.remove('is-active');
                        animate(ball.querySelector('.tree-ball__drop'), reducedMotion
                            ? [{ opacity: 1 }, { opacity: 0 }]
                            : [
                                { transform: 'translateY(0)', opacity: 1 },
                                { transform: 'translateY(8px)', opacity: 1, offset: 0.25 },
                                { transform: 'translateY(-220px)', opacity: 0 },
                            ], {
                            duration: outroMs - 150,
                            delay: i * 40,
                            easing: 'ease-in',
                            fill: 'forwards',
                        });
                    });
                }, Math.max(introMs, configuredDuration - outroMs)));

                return handle;
            },

            /**
             * Random, distinct hang points that keep the baubles apart;
             * prefers points not used in the last round.
             */
            pickTreePoints(count, lastPoints, minDistance = 95) {
                const shuffle = (list) => {
                    const copy = [...list];
                    for (let i = copy.length - 1; i > 0; i--) {
                        const j = Math.floor(Math.random() * (i + 1));
                        [copy[i], copy[j]] = [copy[j], copy[i]];
                    }
                    return copy;
                };
                const all = this.treeHangPoints.map((_, index) => index);
                const fresh = shuffle(all.filter((index) => !lastPoints.includes(index)));
                const used = shuffle(all.filter((index) => lastPoints.includes(index)));

                const candidates = [...fresh, ...used];
                const picked = [];
                const farEnough = (index) => picked.every((other) => {
                    const a = this.treeHangPoints[index];
                    const b = this.treeHangPoints[other];
                    return Math.hypot(a.x - b.x, a.y - b.y) >= minDistance;
                });

                candidates.forEach((index) => {
                    if (picked.length < count && farEnough(index)) {
                        picked.push(index);
                    }
                });
                candidates.forEach((index) => {
                    if (picked.length < count && !picked.includes(index)) {
                        picked.push(index);
                    }
                });

                return picked;
            },

          /**
           * Render Canva slide
           */
          renderCanvaSlide(canva) {
            if (!canva || !canva.embed_url) {
              return '<p>Kein Canva-Design hinterlegt</p>';
            }

            return `
        <iframe
            class="canva-embed"
            src="${this.escapeHtml(canva.embed_url)}"
            frameborder="0"
            scrolling="no"
            allowfullscreen

        ></iframe>
    `;
          },
            /**
             * Render video slide
             */
            renderVideoSlide(video) {
                if (video.source === 'upload') {
                    return `
                        <video
                            ${video.autoplay ? 'autoplay' : ''}
                            ${video.muted ? 'muted' : ''}
                            ${video.loop ? 'loop' : ''}
                            controls
                        >
                            <source src="${video.url}" type="${video.type}">
                            Your browser does not support video playback.
                        </video>
                    `;
                } else {
                    // Embed iframe (simplified, can be enhanced with proper embed detection)
                    return `<iframe src="${video.embed_url}" width="100%" height="100%" frameborder="0" allowfullscreen></iframe>`;
                }
            },

            /**
             * Render calendar slide
             */
            renderCalendarSlide(calendar) {
                if (!calendar.events || calendar.events.length === 0) {
                    if (calendar.error) {
                        return `<div class="calendar-error"><p>Kalenderfehler: ${calendar.error}</p></div>`;
                    }
                    return '<div class="calendar-empty"><p>Keine bevorstehenden Termine</p></div>';
                }

                const layoutClass = `calendar-${calendar.layout || 'list'}`;

                if (calendar.layout === 'grid') {
                    return this.renderCalendarGrid(calendar.events);
                } else if (calendar.layout === 'agenda') {
                    return this.renderCalendarAgenda(calendar.events);
                } else {
                    return this.renderCalendarList(calendar.events);
                }
            },

            /**
             * Render calendar as list
             */
            renderCalendarList(events) {
                return `
                    <div class="calendar-list">
                        <h2 class="calendar-title">Termine</h2>
                        <div class="calendar-scroll-viewport">
                            <div class="calendar-scroll-track">
                                <ul class="event-list">
                                    ${events.map(event => `
                                        <li class="event-item">
                                            <div class="event-date-time">
                                                <span class="event-date">${this.formatEventDate(event)}</span>
                                                ${event.time ? `<span class="event-time">${this.formatEventTimeRange(event)}</span>` : ''}
                                            </div>
                                            <div class="event-details">
                                                <strong class="event-title">${event.title}</strong>
                                                ${event.location ? `<span class="event-location">${event.location}</span>` : ''}
                                                ${event.description ? `<p class="event-description">${event.description}</p>` : ''}
                                            </div>
                                        </li>
                                    `).join('')}
                                </ul>
                            </div>
                        </div>
                    </div>
                `;
            },

            /**
             * Render calendar as agenda
             */
            renderCalendarAgenda(events) {
                // Group events by date
                const grouped = {};
                events.forEach(event => {
                    const dateKey = this.formatEventDate(event);
                    if (!grouped[dateKey]) {
                        grouped[dateKey] = [];
                    }
                    grouped[dateKey].push(event);
                });

                const pages = this.buildAgendaPages(grouped);

                return `
                    <div class="calendar-agenda">
                        <h2 class="calendar-title">Terminplan</h2>
                        <div class="calendar-scroll-viewport">
                            <div class="calendar-scroll-track">
                                ${pages.map(page => `
                                    <section class="agenda-carousel-page">
                                        ${page.map(([date, dateEvents]) => `
                                            <div class="agenda-day">
                                                <h3 class="agenda-date">${date}</h3>
                                                <ul class="agenda-events">
                                                    ${dateEvents.map(event => `
                                                        <li class="agenda-event">
                                                            <div class="agenda-event-time">
                                                                ${event.time ? `<span class="event-time">${this.formatEventTimeRange(event)}</span>` : '<span class="event-time">Ganztägig</span>'}
                                                            </div>
                                                            <div class="agenda-event-details">
                                                                <span class="event-title">${event.title}</span>
                                                                ${event.location ? `<span class="event-location">${event.location}</span>` : ''}
                                                            </div>
                                                        </li>
                                                    `).join('')}
                                                </ul>
                                            </div>
                                        `).join('')}
                                    </section>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                `;
            },

            buildAgendaPages(groupedEvents, maxItemsPerPage = 4) {
                const sourceDays = Object.entries(groupedEvents);
                const pages = [];
                let currentPage = [];
                let itemCount = 0;

                sourceDays.forEach(([date, events]) => {
                    const eventCount = events.length;

                    if (currentPage.length > 0 && itemCount + eventCount > maxItemsPerPage) {
                        pages.push(currentPage);
                        currentPage = [];
                        itemCount = 0;
                    }

                    currentPage.push([date, events]);
                    itemCount += eventCount;

                    if (itemCount >= maxItemsPerPage) {
                        pages.push(currentPage);
                        currentPage = [];
                        itemCount = 0;
                    }
                });

                if (currentPage.length > 0) {
                    pages.push(currentPage);
                }

                return pages.length > 0 ? pages : [sourceDays];
            },

            buildGridPages(events, maxItemsPerPage = 3) {
                const pages = [];
                for (let index = 0; index < events.length; index += maxItemsPerPage) {
                    pages.push(events.slice(index, index + maxItemsPerPage));
                }

                return pages.length > 0 ? pages : [[]];
            },

            /**
             * Render calendar as grid
             */
            renderCalendarGrid(events) {
                const pages = this.buildGridPages(events);

                return `
                    <div class="calendar-grid">
                        <h2 class="calendar-title">Termine</h2>
                        <div class="agenda-carousel-viewport">
                            <div class="agenda-carousel-track">
                                ${pages.map(page => `
                                    <section class="agenda-carousel-page">
                                        <div class="agenda-card-grid">
                                            ${page.map(event => `
                                                <article class="agenda-card">
                                                    <div class="agenda-card-meta">
                                                        <span class="agenda-card-date">${this.formatEventDate(event)}</span>
                                                        ${event.time ? `<span class="agenda-card-time">${this.formatEventTimeRange(event)}</span>` : ''}
                                                    </div>
                                                    <h3 class="agenda-card-title">${event.title}</h3>
                                                    ${event.location ? `<p class="agenda-card-location">${event.location}</p>` : ''}
                                                    ${event.description ? `<p class="agenda-card-description">${event.description}</p>` : ''}
                                                </article>
                                            `).join('')}
                                        </div>
                                    </section>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                `;
            },

            /**
             * Start slide rotation
             */
            startRotation() {
                const slides = document.querySelectorAll('.signage-slide');

                if (slides.length === 0) {
                    console.warn('⚠️ No slides to rotate');
                    return;
                }

                this.showSlide(0);
            },

            /**
             * Show specific slide
             */
            showSlide(index) {
                const slides = document.querySelectorAll('.signage-slide');

                if (slides.length === 0) return;

                // Hide all slides
                slides.forEach(s => s.classList.remove('active'));

                // Show current slide
                const currentSlide = slides[index];
                currentSlide.classList.add('active');

                const configuredDuration = parseInt(currentSlide.dataset.duration) * 1000;
                this.startSlideAnimation(currentSlide, configuredDuration);

                // Schedule next slide
                this.state.currentSlideIndex = index;
                clearTimeout(this.state.slideTimeout);

                this.state.slideTimeout = setTimeout(() => {
                    const nextIndex = (index + 1) % slides.length;
                    this.showSlide(nextIndex);
                }, configuredDuration);
            },

            startSlideAnimation(slideEl, configuredDuration) {
                if (this.state.activeAnimation) {
                    this.state.activeAnimation.cancel();
                    this.state.activeAnimation = null;
                }

                if (slideEl.treeData) {
                    this.state.activeAnimation = this.startChristmasTree(slideEl, configuredDuration);
                    return configuredDuration;
                }

                const carouselTrack = slideEl.querySelector('.agenda-carousel-track');
                const carouselPages = slideEl.querySelectorAll('.agenda-carousel-page');
                if (carouselTrack && carouselPages.length > 1) {
                    carouselTrack.style.transform = 'translateX(0)';
                    const pageCount = carouselPages.length;
                    const animationDuration = configuredDuration;
                    const frames = [];
                    const segmentDuration = animationDuration / pageCount;
                    const holdRatio = pageCount === 1 ? 1 : 0.78;
                    const trackStyles = window.getComputedStyle(carouselTrack);
                    const pageGap = parseFloat(trackStyles.columnGap || trackStyles.gap || '0') || 0;
                    const pageStep = carouselPages[0].offsetWidth + pageGap;

                    for (let index = 0; index < pageCount; index++) {
                        const transform = `translateX(-${index * pageStep}px)`;
                        const segmentStart = (segmentDuration * index) / animationDuration;
                        const holdOffset = Math.min(segmentStart + ((segmentDuration * holdRatio) / animationDuration), 1);
                        const transitionOffset = Math.min(((segmentDuration * (index + 1)) / animationDuration), 1);

                        frames.push({ transform, offset: segmentStart });
                        frames.push({ transform, offset: holdOffset });

                        if (index < pageCount - 1) {
                            frames.push({
                                transform: `translateX(-${(index + 1) * pageStep}px)`,
                                offset: transitionOffset,
                            });
                        }
                    }

                    frames.push({
                        transform: `translateX(-${(pageCount - 1) * pageStep}px)`,
                        offset: 1,
                    });

                    this.state.activeAnimation = carouselTrack.animate(frames, {
                        duration: animationDuration,
                        easing: 'ease-in-out',
                        fill: 'forwards',
                    });
                    return configuredDuration;
                }

                const viewport = slideEl.querySelector('.calendar-scroll-viewport');
                const track = slideEl.querySelector('.calendar-scroll-track');
                if (!viewport || !track) {
                    return configuredDuration;
                }

                track.style.transform = 'translateY(0)';

                const overflow = track.scrollHeight - viewport.clientHeight;
                if (overflow <= 24) {
                    return configuredDuration;
                }

                const animationDuration = configuredDuration;
                const pauseRatio = 0.16;
                const topPauseOffset = pauseRatio;
                const bottomPauseOffset = 1 - pauseRatio;

                this.state.activeAnimation = track.animate(
                    [
                        { transform: 'translateY(0)', offset: 0 },
                        { transform: 'translateY(0)', offset: topPauseOffset },
                        { transform: `translateY(-${overflow}px)`, offset: bottomPauseOffset },
                        { transform: `translateY(-${overflow}px)`, offset: 1 },
                    ],
                    {
                        duration: animationDuration,
                        easing: 'linear',
                        fill: 'forwards',
                    }
                );

                return configuredDuration;
            },

            /**
             * Show access pending screen
             */
            showAccessPending() {
                const player = document.getElementById('signage-player');
                player.innerHTML = `
                    <div id="access-pending">
                        <h1>Zugriff wird neu angefragt</h1>
                        <p>Dieses Geraet wurde von diesem Monitor getrennt und wird zur Landing Page zurueckgeleitet.</p>
                        <div class="device-id">
                            <strong>Device ID:</strong><br>
                            ${this.state.uuid}
                        </div>
                    </div>
                `;
                this.redirectToOnboarding();
            },

            /**@abstract
             * Show access denied screen
             */

            showAccessDenied(message) {
                const player = document.getElementById('signage-player');
                player.innerHTML = `
                    <div style="text-align: center; padding: 2rem;">
                        <h1>🚫 Access Denied</h1>
                        <p>${message || 'This device is not authorized to display content.'}</p>
                        <p style="margin-top: 1rem;">Weiterleitung zur Landing Page...</p>
                    </div>
                `;
                this.redirectToOnboarding();
            },

            /**
             * Show standby screen
             */
            showStandby(data) {
                const player = document.getElementById('signage-player');
                let content = '';
                let modeClass = '';

                if (data.standby_mode === 'custom') {
                    content = `
                        ${data.standby_image ? `<img src="${data.standby_image}" alt="Standby">` : ''}
                        ${data.standby_message ? `<p>${data.standby_message}</p>` : ''}
                    `;
                } else if (data.standby_mode === 'logo') {
                    modeClass = ' standby-logo-mode';
                    content = `<img src="${this.config.standbyLogoUrl}" alt="MachMit Haus Logo">`;
                }

                player.innerHTML = `<div id="standby-screen" class="${modeClass.trim()}">${content}</div>`;
            },

            /**
             * Show error
             */
            showError(message) {
                const player = document.getElementById('signage-player');
                player.innerHTML = `
                    <div style="text-align: center; padding: 2rem;">
                        <h1>⚠️ Error</h1>
                        <p>${message}</p>
                    </div>
                `;
            },

            /**
             * Show loading indicator
             */
            showLoading(show) {
                const indicator = document.getElementById('loading-indicator');
                indicator.classList.toggle('visible', show);
            },
        };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            SignagePlayer.init();
        });
    </script>
</body>
</html>
