<?php
/**
 * Screen Template
 *
 * Frontend display template for digital signage screens.
 * Handles access control, content loading, and player initialization.
 */
?>
<!DOCTYPE html>
<html lang="en">
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
<body data-orientation="<?= $page->orientation() ?>">

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
                requiresAccessControl: <?= $page->whitelist_enabled()->toBool() ? 'true' : 'false' ?>,
                syncInterval: 1000, // Check access changes every second
            },

            state: {
                uuid: null,
                accessStatus: null,
                contentData: null,
                contentRevision: null,
                currentSlideIndex: 0,
                activeAnimation: null,
                slideTimeout: null,
                syncIntervalId: null,
                redirectTimeoutId: null,
            },

            /**
             * Initialize player
             */
            async init() {
                console.log('🎬 Signage Player initializing...');

                if (!this.config.requiresAccessControl) {
                    await this.loadContent();
                    this.startSync();
                    return;
                }

                // Generate or retrieve device UUID
                this.state.uuid = this.getOrCreateUUID();
                console.log('📱 Device UUID:', this.state.uuid);

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

                    console.log('🔐 Access status:', data.access);

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
                    const response = await fetch(`${this.config.apiBase}/content/${this.config.screenSlug}`);
                    const data = await response.json();

                    this.state.contentData = data;
                    this.state.contentRevision = data.revision || null;
                    console.log('📥 Content data loaded:', data);

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
                    const response = await fetch(`${this.config.apiBase}/content-state/${this.config.screenSlug}`);
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
                console.log('🖥️ Rendering slides...', this.state.contentData.channel);
                player.style.backgroundColor = this.state.contentData.channel.background.background_color || '#000000';
                slides.forEach((slide, index) => {
                    const slideEl = document.createElement('div');
                    const contentPosition = slide.content_position || 'center';
                    const effectiveBackground = this.getEffectiveBackground(slide.background);
                    slideEl.className = `signage-slide content-position-${contentPosition}`;
                    slideEl.dataset.transition = slide.transition || 'fade';
                    slideEl.dataset.duration = slide.duration;
                    slideEl.dataset.contentPosition = contentPosition;
                    slideEl.style.transitionDuration = `${slide.transition_duration || 1}s`;

                    // Apply background
                    this.applyBackground(slideEl, effectiveBackground);
                    this.applyTextTheme(slideEl, effectiveBackground);

                    // Render content
                    const contentWrapper = document.createElement('div');
                    contentWrapper.className = 'slide-content';
                    contentWrapper.innerHTML = this.renderSlideContent(slide);
                    
                    slideEl.appendChild(contentWrapper);
                    player.appendChild(slideEl);
                });

                console.log(`✅ Rendered ${slides.length} slides`);
            },

            getEffectiveBackground(slideBackground) {
                if (slideBackground && slideBackground.type && slideBackground.type !== 'none') {
                    return slideBackground;
                }

                return this.state.contentData?.channel?.background || slideBackground;
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
                    slideEl.style.backgroundPosition = background.image.position || 'center';
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
                    
                    const opacity = background.overlay.opacity / 100;
                    const color = background.overlay.color;
                    const gradient = background.overlay.gradient;
                    
                    if (gradient !== 'none') {
                        // Apply gradient overlay
                        const gradientDirection = gradient; // e.g., 'to-bottom'
                        const transparentColor = this.hexToRgba(color, 0);
                        const opaqueColor = this.hexToRgba(color, opacity);
                        overlay.style.background = `linear-gradient(${gradientDirection}, ${transparentColor}, ${opaqueColor})`;
                    } else {
                        // Apply solid overlay
                        overlay.style.backgroundColor = this.hexToRgba(color, opacity);
                    }
                    overlay.style.width = '100%';
                    overlay.style.height = '100%';
                    overlay.style.position = 'absolute';
                    overlay.style.top = '0';
                    slideEl.appendChild(overlay);
                }
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
                    case 'video':
                        return this.renderVideoSlide(slide.video);
                    case 'calendar':
                        return this.renderCalendarSlide(slide.calendar);
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

            getBlockText(block) {
                if (!block || block.text == null) {
                    return '';
                }

                return typeof block.text === 'string'
                    ? block.text
                    : (block.text.value || '');
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
                console.log(`📺 Showing slide ${index + 1}/${slides.length} for ${configuredDuration / 1000}s`);

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
