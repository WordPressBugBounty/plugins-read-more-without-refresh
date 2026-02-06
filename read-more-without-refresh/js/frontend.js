/**
 * Read More Without Refresh - Frontend JavaScript (Free Version)
 * 
 * @package ReadMoreWithoutRefresh
 * @version 3.4.0
 */

(function() {
    'use strict';

    /**
     * Main RMWR object (Free Version)
     */
    window.RMWR = {
        /**
         * Toggle read more/less functionality
         * 
         * @param {string} id - Unique ID of the read more instance
         * @param {string} openText - Text to show when collapsed
         * @param {string} closeText - Text to show when expanded
         */
        toggle: function(id, openText, closeText) {
            const link = document.getElementById('readlink' + id);
            const content = document.getElementById('read' + id);

            if (!link || !content) {
                return;
            }

            const isExpanded = content.style.display !== 'none' && content.getAttribute('aria-hidden') !== 'true';
            
            // Get text element (free version doesn't have icons)
            const textElement = link.querySelector('.rmwr-text') || link;
            
            // Get wrapper for data attributes
            const wrapper = link.closest('.rmwr-wrapper');
            
            // Toggle visibility with basic animation (free version - fade only)
            if (isExpanded) {
                this.collapse(content);
                if (textElement === link) {
                    link.innerHTML = this.escapeHtml(openText);
                } else {
                    textElement.textContent = openText;
                }
                link.setAttribute('aria-expanded', 'false');
                content.setAttribute('aria-hidden', 'true');
            } else {
                this.expand(content);
                if (textElement === link) {
                    link.innerHTML = this.escapeHtml(closeText);
                } else {
                    textElement.textContent = closeText;
                }
                link.setAttribute('aria-expanded', 'true');
                content.setAttribute('aria-hidden', 'false');
                
                // Smooth scroll if enabled (Free feature)
                if (wrapper && wrapper.dataset.smoothScroll !== 'false') {
                    const offset = parseInt(wrapper.dataset.scrollOffset || 0);
                    this.smoothScrollTo(content, offset);
                }
            }
        },

        /**
         * Expand content with basic fade animation (free version)
         */
        expand: function(element) {
            element.style.display = 'block';
            
            // Basic fade animation for free version
            const animation = element.dataset.animation || 'fade';
            
            if (animation === 'fade') {
                element.style.opacity = '0';
                element.style.overflow = 'hidden';
                
                // Trigger reflow
                element.offsetHeight;
                
                const duration = 300; // Fixed duration for free version
                element.style.transition = `opacity ${duration}ms ease-in-out`;
                element.style.opacity = '1';
                
                // Clean up after animation
                setTimeout(function() {
                    element.style.overflow = '';
                    element.style.transition = '';
                }, duration);
            } else {
                // Simple show
                element.style.display = 'block';
            }
        },

        /**
         * Collapse content with basic animation (free version)
         */
        collapse: function(element) {
            const animation = element.dataset.animation || 'fade';
            
            if (animation === 'fade') {
                const duration = 300; // Fixed duration for free version
                element.style.transition = `opacity ${duration}ms ease-in-out`;
                element.style.opacity = '0';
                element.style.overflow = 'hidden';
                
                setTimeout(function() {
                    element.style.display = 'none';
                    element.style.overflow = '';
                    element.style.transition = '';
                }, duration);
            } else {
                element.style.display = 'none';
            }
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        /**
         * Smooth scroll to element (Free feature)
         */
        smoothScrollTo: function(element, offset) {
            if (!element) return;
            
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - (offset || 0);
            
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        },

        /**
         * Initialize all read more instances
         */
        init: function() {
            // Handle click events using event delegation
            document.addEventListener('click', function(e) {
                const link = e.target.closest('.read-link');
                if (!link) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                const id = link.id.replace('readlink', '');
                const openText = link.dataset.openText || link.getAttribute('data-open-text') || 'Read More';
                const closeText = link.dataset.closeText || link.getAttribute('data-close-text') || 'Read Less';

                RMWR.toggle(id, openText, closeText);
            });

            // Handle keyboard navigation (Enter and Space keys)
            document.addEventListener('keydown', function(e) {
                const link = e.target.closest('.read-link');
                if (!link || (e.key !== 'Enter' && e.key !== ' ')) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                const id = link.id.replace('readlink', '');
                const openText = link.dataset.openText || link.getAttribute('data-open-text') || 'Read More';
                const closeText = link.dataset.closeText || link.getAttribute('data-close-text') || 'Read Less';

                RMWR.toggle(id, openText, closeText);
            });
            
            // Handle print - auto-expand all (Free feature)
            if (typeof rmwrSettings !== 'undefined' && rmwrSettings.printExpand !== false) {
                window.addEventListener('beforeprint', function() {
                    const allContent = document.querySelectorAll('.read_div[style*="display: none"]');
                    allContent.forEach(function(content) {
                        content.style.display = 'block';
                        content.setAttribute('aria-hidden', 'false');
                        const contentId = content.id.replace('read', '');
                        const contentLink = document.getElementById('readlink' + contentId);
                        if (contentLink) {
                            contentLink.setAttribute('aria-expanded', 'true');
                        }
                    });
                });
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', RMWR.init);
    } else {
        RMWR.init();
    }

})();
