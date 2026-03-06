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
                checkInterval: 5000, // Check access every 5 seconds if pending
                refreshInterval: 60000, // Refresh content every minute
            },

            state: {
                uuid: null,
                accessStatus: null,
                contentData: null,
                currentSlideIndex: 0,
                slideTimeout: null,
                accessIntervalId: null,
                refreshIntervalId: null,
            },

            /**
             * Initialize player
             */
            async init() {
                console.log('🎬 Signage Player initializing...');

                // Generate or retrieve device UUID
                this.state.uuid = this.getOrCreateUUID();
                console.log('📱 Device UUID:', this.state.uuid);

                // Check access
                await this.checkAccess();

                // Start access check interval if pending
                if (this.state.accessStatus === 'pending') {
                    this.state.accessIntervalId = setInterval(
                        () => this.checkAccess(),
                        this.config.checkInterval
                    );
                }

                // Start content refresh interval if granted
                if (this.state.accessStatus === 'granted') {
                    this.state.refreshIntervalId = setInterval(
                        () => this.refreshContent(),
                        this.config.refreshInterval
                    );
                }
            },

            /**
             * Get or create device UUID
             */
            getOrCreateUUID() {
                let uuid = localStorage.getItem('signage_device_uuid');

                if (!uuid) {
                    uuid = this.generateUUID();
                    localStorage.setItem('signage_device_uuid', uuid);
                }

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
                        await this.loadContent();
                    } else if (data.access === 'pending') {
                        this.showAccessPending();
                    } else {
                        this.stopAccessChecks();
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

            /**
             * Refresh content (check for updates)
             */
            async refreshContent() {
                console.log('🔄 Refreshing content...');
                await this.loadContent();
            },
            stopAccessChecks() {
                if (this.state.accessIntervalId) {
                    clearInterval(this.state.accessIntervalId);
                    this.state.accessIntervalId = null;
                }
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
                    slideEl.className = `signage-slide content-position-${contentPosition}`;
                    slideEl.dataset.transition = slide.transition || 'fade';
                    slideEl.dataset.duration = slide.duration;
                    slideEl.dataset.contentPosition = contentPosition;
                    slideEl.style.transitionDuration = `${slide.transition_duration || 1}s`;

                    // Apply background
                    this.applyBackground(slideEl, slide.background);

                    // Render content
                    const contentWrapper = document.createElement('div');
                    contentWrapper.className = 'slide-content';
                    contentWrapper.innerHTML = this.renderSlideContent(slide);
                    
                    slideEl.appendChild(contentWrapper);
                    player.appendChild(slideEl);
                });

                console.log(`✅ Rendered ${slides.length} slides`);
            },

            /**
             * Apply background to slide element
             */
            applyBackground(slideEl, background) {
                if (!background || background.type === 'none') {
                    return; // Use channel default
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

            /**
             * Render individual block
             */
            renderBlock(block) {
                switch (block.type) {
                    case 'signage-heading':
                        return `<${block.level || 'h2'}>${block.text.value}</${block.level || 'h2'}>`;
                    
                    case 'signage-text':
                        return `<div class="text-block">${block.text.value}</div>`;
                    
                    case 'image':
                        if (!block.src) return '';
                        return `
                            <figure class="image-block">
                                <img src="${block.src}" alt="${block.alt || ''}">
                                ${block.caption ? `<figcaption>${block.caption}</figcaption>` : ''}
                            </figure>
                        `;
                    
                    case 'list':
                        return `<div class="list-block">${block.text.value}</div>`;
                    
                    case 'quote':
                        return `
                            <blockquote class="quote-block">
                                ${block.text.value}
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
                        return `<div class="calendar-error"><p>Calendar Error: ${calendar.error}</p></div>`;
                    }
                    return '<div class="calendar-empty"><p>No upcoming events</p></div>';
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
                        <h2 class="calendar-title">Upcoming Events</h2>
                        <ul class="event-list">
                            ${events.map(event => `
                                <li class="event-item">
                                    <div class="event-date-time">
                                        <span class="event-date">${event.date || ''}</span>
                                        ${event.time ? `<span class="event-time">${event.time}${event.end_time ? ' - ' + event.end_time : ''}</span>` : ''}
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
                `;
            },

            /**
             * Render calendar as agenda
             */
            renderCalendarAgenda(events) {
                // Group events by date
                const grouped = {};
                events.forEach(event => {
                    const dateKey = event.date || 'No Date';
                    if (!grouped[dateKey]) {
                        grouped[dateKey] = [];
                    }
                    grouped[dateKey].push(event);
                });

                return `
                    <div class="calendar-agenda">
                        <h2 class="calendar-title">Schedule</h2>
                        ${Object.entries(grouped).map(([date, dateEvents]) => `
                            <div class="agenda-day">
                                <h3 class="agenda-date">${date}</h3>
                                <ul class="agenda-events">
                                    ${dateEvents.map(event => `
                                        <li class="agenda-event">
                                            ${event.time ? `<span class="event-time">${event.time}</span>` : ''}
                                            <span class="event-title">${event.title}</span>
                                            ${event.location ? `<span class="event-location">${event.location}</span>` : ''}
                                        </li>
                                    `).join('')}
                                </ul>
                            </div>
                        `).join('')}
                    </div>
                `;
            },

            /**
             * Render calendar as grid
             */
            renderCalendarGrid(events) {
                return `
                    <div class="calendar-grid">
                        <h2 class="calendar-title">Events</h2>
                        <div class="event-cards">
                            ${events.map(event => `
                                <div class="event-card">
                                    <div class="event-card-header">
                                        <span class="event-date">${event.date || ''}</span>
                                        ${event.time ? `<span class="event-time">${event.time}</span>` : ''}
                                    </div>
                                    <h3 class="event-title">${event.title}</h3>
                                    ${event.location ? `<p class="event-location">${event.location}</p>` : ''}
                                    ${event.description ? `<p class="event-description">${event.description}</p>` : ''}
                                </div>
                            `).join('')}
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

                const duration = parseInt(currentSlide.dataset.duration) * 1000;
                console.log(`📺 Showing slide ${index + 1}/${slides.length} for ${duration / 1000}s`);

                // Schedule next slide
                this.state.currentSlideIndex = index;
                clearTimeout(this.state.slideTimeout);

                this.state.slideTimeout = setTimeout(() => {
                    const nextIndex = (index + 1) % slides.length;
                    this.showSlide(nextIndex);
                }, duration);
            },

            /**
             * Show access pending screen
             */
            showAccessPending() {
                const player = document.getElementById('signage-player');
                player.innerHTML = `
                    <div id="access-pending">
                        <h1>Access Request Pending</h1>
                        <p>This device is waiting for approval to display content.</p>
                        <div class="device-id">
                            <strong>Device ID:</strong><br>
                            ${this.state.uuid}
                        </div>
                        <p style="margin-top: 2rem; font-size: 0.9rem;">
                            Contact your administrator to approve this device.
                        </p>
                    </div>
                `;
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
                    </div>
                `;
            },

            /**
             * Show standby screen
             */
            showStandby(data) {
                const player = document.getElementById('signage-player');
                let content = '';

                if (data.standby_mode === 'custom') {
                    content = `
                        ${data.standby_image ? `<img src="${data.standby_image}" alt="Standby">` : ''}
                        ${data.standby_message ? `<p>${data.standby_message}</p>` : ''}
                    `;
                } else if (data.standby_mode === 'logo') {
                    content = '<p>Display Offline</p>';
                }

                player.innerHTML = `<div id="standby-screen">${content}</div>`;
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
