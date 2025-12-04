/* ==========================================================
 * Bootstrap Core JavaScript (v2.0.0 Refactor)
 * Consolidated, fixed, and modernized for jQuery 3.x+
 * ========================================================== */

!function ($) {
    "use strict";

    // --- 1. CSS TRANSITION SUPPORT (bootstrap-transition.js) ---
    // Simplified, modern feature-detection for transition end event
    $.support.transition = (function () {
        var thisBody = document.body || document.documentElement;
        var support = thisBody.style.transition !== undefined || thisBody.style.WebkitTransition !== undefined || thisBody.style.MozTransition !== undefined || thisBody.style.MsTransition !== undefined || thisBody.style.OTransition !== undefined;

        if (!support) return false;

        return {
            end: (function () {
                var el = document.createElement('div');
                var transEndEventNames = {
                    'WebkitTransition': 'webkitTransitionEnd',
                    'MozTransition': 'transitionend',
                    'OTransition': 'oTransitionEnd',
                    'transition': 'transitionend'
                };
                for (var name in transEndEventNames) {
                    if (el.style[name] !== undefined) {
                        return transEndEventNames[name];
                    }
                }
            })()
        };
    })();

    // --- 2. ALERT CLASS DEFINITION (bootstrap-alert.js) ---
    var dismiss = '[data-dismiss="alert"]';

    var Alert = function (el) {
        $(el).on('click', dismiss, this.close);
    };

    Alert.prototype = {
        constructor: Alert,

        close: function (e) {
            var $this = $(this);
            var selector = $this.attr('data-target');
            var $parent;

            if (!selector) {
                selector = $this.attr('href');
                selector = selector && selector.replace(/.*(?=#[^\s]*$)/, ''); // strip for ie7
            }

            $parent = $(selector);
            $parent.trigger('close');

            e && e.preventDefault();

            $parent.length || ($parent = $this.hasClass('alert') ? $this : $this.parent());

            $parent.removeClass('in');

            function removeElement() {
                $parent.remove();
                $parent.trigger('closed');
            }

            // Fixed: Use modern transition support check
            $.support.transition && $parent.hasClass('fade') ?
            $parent.on($.support.transition.end, removeElement) :
            removeElement();
        }
    };

    /* ALERT PLUGIN DEFINITION */
    $.fn.alert = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('alert');
            if (!data) $this.data('alert', (data = new Alert(this)));
            if (typeof option == 'string') data[option].call($this);
        });
    };
    $.fn.alert.Constructor = Alert;

    /* ALERT DATA-API */
    $(function () {
        $('body').on('click.alert.data-api', dismiss, Alert.prototype.close);
    });


    // --- 3. BUTTON CLASS DEFINITION (bootstrap-button.js) ---
    var Button = function (element, options) {
        this.$element = $(element);
        this.options = $.extend({}, $.fn.button.defaults, options);
    };

    Button.prototype = {
        constructor: Button,

        setState: function (state) {
            var d = 'disabled';
            var $el = this.$element;
            var data = $el.data();
            var val = $el.is('input') ? 'val' : 'html';

            state = state + 'Text';
            data.resetText || $el.data('resetText', $el[val]());

            $el[val](data[state] || this.options[state]);

            // push to event loop to allow forms to submit
            setTimeout(function () {
                state == 'loadingText' ?
                $el.addClass(d).attr(d, d) :
                $el.removeClass(d).removeAttr(d);
            }, 0);
        },

        toggle: function () {
            var $parent = this.$element.parent('[data-toggle="buttons-radio"]');

            $parent && $parent
                .find('.active')
                .removeClass('active');

            this.$element.toggleClass('active');
        }
    };

    /* BUTTON PLUGIN DEFINITION */
    $.fn.button = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('button');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('button', (data = new Button(this, options)));
            if (option == 'toggle') data.toggle();
            else if (option) data.setState(option);
        });
    };
    $.fn.button.defaults = {
        loadingText: 'loading...'
    };
    $.fn.button.Constructor = Button;

    /* BUTTON DATA-API */
    $(function () {
        $('body').on('click.button.data-api', '[data-toggle^=button]', function (e) {
            $(e.target).button('toggle');
        });
    });


    // --- 4. CAROUSEL CLASS DEFINITION (bootstrap-carousel.js) ---
    var Carousel = function (element, options) {
        this.$element = $(element);
        this.options = $.extend({}, $.fn.carousel.defaults, options);
        this.options.slide && this.slide(this.options.slide);
    };

    Carousel.prototype = {
        cycle: function () {
            this.interval = setInterval($.proxy(this.next, this), this.options.interval);
            return this;
        },

        to: function (pos) {
            var $active = this.$element.find('.active');
            var children = $active.parent().children();
            var activePos = children.index($active);
            var that = this;

            if (pos > (children.length - 1) || pos < 0) return;

            if (this.sliding) {
                return this.$element.one('slid', function () {
                    that.to(pos);
                });
            }

            if (activePos == pos) return this.pause().cycle();

            return this.slide(pos > activePos ? 'next' : 'prev', $(children[pos]));
        },

        pause: function () {
            clearInterval(this.interval);
            return this;
        },

        next: function () {
            if (this.sliding) return; // FIXED: Logic correction (was syntactically broken)
            return this.slide('next');
        },

        prev: function () {
            if (this.sliding) return; // FIXED: Logic correction (was syntactically broken)
            return this.slide('prev');
        },

        slide: function (type, next) {
            var $active = this.$element.find('.active');
            var $next = next || $active[type]();
            var isCycling = this.interval;
            var direction = type == 'next' ? 'left' : 'right';
            var fallback = type == 'next' ? 'first' : 'last';
            var that = this;

            this.sliding = true;

            isCycling && this.pause();

            $next = $next.length ? $next : this.$element.find('.item')[fallback]();

            // Check if next slide is the active slide, if so, do nothing
            if ($next.is($active)) {
                this.sliding = false;
                isCycling && this.cycle();
                return this;
            }

            if (!$.support.transition && this.$element.hasClass('slide')) {
                this.$element.trigger('slide');
                $active.removeClass('active');
                $next.addClass('active');
                this.sliding = false;
                this.$element.trigger('slid');
            } else {
                $next.addClass(type);
                $next[0].offsetWidth; // force reflow
                $active.addClass(direction);
                $next.addClass(direction);
                this.$element.trigger('slide');
                this.$element.one($.support.transition.end, function () {
                    $next.removeClass([type, direction].join(' ')).addClass('active');
                    $active.removeClass(['active', direction].join(' '));
                    that.sliding = false;
                    setTimeout(function () { that.$element.trigger('slid'); }, 0);
                });
            }

            isCycling && this.cycle();

            return this;
        }
    };

    /* CAROUSEL PLUGIN DEFINITION */
    $.fn.carousel = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('carousel');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('carousel', (data = new Carousel(this, options)));
            
            if (typeof option == 'number') {
                data.to(option);
            } else {
                var method = (typeof option == 'string') ? option : options.slide;
                if (method) {
                    data[method]();
                } else {
                    data.cycle();
                }
            }
        });
    };
    $.fn.carousel.defaults = {
        interval: 5000
    };
    $.fn.carousel.Constructor = Carousel;

    /* CAROUSEL DATA-API */
    $(function () {
        $('body').on('click.carousel.data-api', '[data-slide]', function (e) {
            var $this = $(this);
            var href;
            var $target = $($this.attr('data-target') || (href = $this.attr('href')) && href.replace(/.*(?=#[^\s]+$)/, '')); // strip for ie7
            var options = !$target.data('modal') && $.extend({}, $target.data(), $this.data());
            $target.carousel(options);
            e.preventDefault();
        });
    });


    // --- 5. COLLAPSE CLASS DEFINITION (bootstrap-collapse.js) ---
    var Collapse = function (element, options) {
        this.$element = $(element);
        this.options = $.extend({}, $.fn.collapse.defaults, options);

        if (this.options["parent"]) {
            this.$parent = $(this.options["parent"]);
        }

        this.options.toggle && this.toggle();
    };

    Collapse.prototype = {
        constructor: Collapse,

        dimension: function () {
            var hasWidth = this.$element.hasClass('width');
            return hasWidth ? 'width' : 'height';
        },

        show: function () {
            var dimension = this.dimension();
            var scroll = $.camelCase(['scroll', dimension].join('-'));
            var actives = this.$parent && this.$parent.find('>.accordion-group>.in'); // Targeting: find active child panels
            var hasData;

            if (actives && actives.length) {
                hasData = actives.data('collapse');
                actives.collapse('hide');
                hasData || actives.data('collapse', null);
            }

            this.$element[dimension](0);
            this.transition('addClass', 'show', 'shown');
            this.$element[dimension](this.$element[0][scroll]);
        },

        hide: function () {
            var dimension = this.dimension();
            this.reset(this.$element[dimension]());
            this.transition('removeClass', 'hide', 'hidden');
            this.$element[dimension](0);
        },

        reset: function (size) {
            var dimension = this.dimension();

            this.$element
                .removeClass('collapse')
                [dimension](size || 'auto')
                [0].offsetWidth;

            this.$element.addClass('collapse');
        },

        transition: function (method, startEvent, completeEvent) {
            var that = this;
            var complete = function () {
                if (startEvent == 'show') {
                    that.reset();
                    that.$element.trigger(completeEvent);
                }
            };

            this.$element
                .trigger(startEvent)
                [method]('in');

            $.support.transition && this.$element.hasClass('collapse') ?
            this.$element.one($.support.transition.end, complete) :
            complete();
        },

        toggle: function () {
            this[this.$element.hasClass('in') ? 'hide' : 'show']();
        }
    };

    /* COLLAPSIBLE PLUGIN DEFINITION */
    $.fn.collapse = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('collapse');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('collapse', (data = new Collapse(this, options)));
            if (typeof option == 'string') data[option]();
        });
    };
    $.fn.collapse.defaults = {
        toggle: true
    };
    $.fn.collapse.Constructor = Collapse;

    /* COLLAPSIBLE DATA-API */
    $(function () {
        $('body').on('click.collapse.data-api', '[data-toggle=collapse]', function (e) {
            var $this = $(this);
            var href;
            var target = $this.attr('data-target') || e.preventDefault() || (href = $this.attr('href')) && href.replace(/.*(?=#[^\s]+$)/, ''); // strip for ie7
            var option = $(target).data('collapse') ? 'toggle' : $this.data();
            $(target).collapse(option);
        });
    });


    // --- 6. DROPDOWN CLASS DEFINITION (bootstrap-dropdown.js) ---
    var toggle = '[data-toggle="dropdown"]';

    var Dropdown = function (element) {
        var $el = $(element).on('click.dropdown.data-api', this.toggle);
        $('html').on('click.dropdown.data-api', function () {
            $el.parent().removeClass('open');
        });
    };

    Dropdown.prototype = {
        constructor: Dropdown,

        toggle: function (e) {
            var $this = $(this);
            var selector = $this.attr('data-target');
            var $parent;
            var isActive;

            if (!selector) {
                selector = $this.attr('href');
                selector = selector && selector.replace(/.*(?=#[^\s]*$)/, ''); // strip for ie7
            }

            $parent = $(selector);
            $parent.length || ($parent = $this.parent());

            isActive = $parent.hasClass('open');

            clearMenus();
            !isActive && $parent.toggleClass('open');

            return false;
        }
    };

    function clearMenus() {
        $(toggle).parent().removeClass('open');
    }

    /* DROPDOWN PLUGIN DEFINITION */
    $.fn.dropdown = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('dropdown');
            if (!data) $this.data('dropdown', (data = new Dropdown(this)));
            if (typeof option == 'string') data[option].call($this);
        });
    };
    $.fn.dropdown.Constructor = Dropdown;

    /* APPLY TO STANDARD DROPDOWN ELEMENTS */
    $(function () {
        $('html').on('click.dropdown.data-api', clearMenus);
        $('body').on('click.dropdown.data-api', toggle, Dropdown.prototype.toggle);
    });


    // --- 7. MODAL CLASS DEFINITION (bootstrap-modal.js) ---
    var Modal = function (content, options) {
        this.options = $.extend({}, $.fn.modal.defaults, options);
        this.$element = $(content).delegate('[data-dismiss="modal"]', 'click.dismiss.modal', $.proxy(this.hide, this));
    };

    Modal.prototype = {
        constructor: Modal,

        toggle: function () {
            return this[!this.isShown ? 'show' : 'hide']();
        },

        show: function () {
            var that = this;
            // FIX: Corrected logic to exit if already shown
            if (this.isShown) return; 

            $(document).off('keyup.dismiss.modal'); // Ensure clean state before showing

            $(document).on('keyup.dismiss.modal', function (e) {
                e.which == 27 && that.hide();
            });

            $('body').addClass('modal-open');

            this.isShown = true;
            this.$element.trigger('show');

            backdrop.call(this, function () {
                var transition = $.support.transition && that.$element.hasClass('fade');

                !that.$element.parent().length && that.$element.appendTo(document.body); // don't move modals dom position

                that.$element
                    .show();

                if (transition) {
                    that.$element[0].offsetWidth; // force reflow
                }

                that.$element.addClass('in');

                transition ?
                that.$element.one($.support.transition.end, function () { that.$element.trigger('shown'); }) :
                that.$element.trigger('shown');
            });
        },

        hide: function (e) {
            e && e.preventDefault();
            // FIX: Corrected logic to exit if already hidden
            if (!this.isShown) return;

            var that = this;
            this.isShown = false;

            $(document).off('keyup.dismiss.modal');

            $('body').removeClass('modal-open');

            this.$element
                .trigger('hide')
                .removeClass('in');

            $.support.transition && this.$element.hasClass('fade') ?
            hideWithTransition.call(this) :
            hideModal.call(this);
        }
    };

    /* MODAL PRIVATE METHODS */
    function hideWithTransition() {
        var that = this;
        var timeout = setTimeout(function () {
            that.$element.off($.support.transition.end);
            hideModal.call(that);
        }, 500);

        this.$element.one($.support.transition.end, function () {
            clearTimeout(timeout);
            hideModal.call(that);
        });
    }

    function hideModal() {
        this.$element
            .hide()
            .trigger('hidden');

        backdrop.call(this);
    }

    function backdrop(callback) {
        var that = this;
        var animate = this.$element.hasClass('fade') ? 'fade' : '';

        if (this.isShown && this.options.backdrop) {
            var doAnimate = $.support.transition && animate;

            this.$backdrop = $('<div class="modal-backdrop ' + animate + '" />')
                .appendTo(document.body);

            if (this.options.backdrop != 'static') {
                this.$backdrop.click($.proxy(this.hide, this));
            }

            if (doAnimate) {
                this.$backdrop[0].offsetWidth; // force reflow
            }

            this.$backdrop.addClass('in');

            doAnimate ?
            this.$backdrop.one($.support.transition.end, callback) :
            callback();

        } else if (!this.isShown && this.$backdrop) {
            this.$backdrop.removeClass('in');

            $.support.transition && this.$element.hasClass('fade') ?
            this.$backdrop.one($.support.transition.end, $.proxy(removeBackdrop, this)) :
            removeBackdrop.call(this);

        } else if (callback) {
            callback();
        }
    }

    function removeBackdrop() {
        this.$backdrop.remove();
        this.$backdrop = null;
    }

    /* MODAL PLUGIN DEFINITION */
    $.fn.modal = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('modal');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('modal', (data = new Modal(this, options)));
            
            // FIX: Corrected syntax to use 'if/else' block properly
            if (typeof option == 'string') {
                data[option]();
            } else if (option !== undefined) { // If option is defined but not string, treat as show (default Bootstrap 2 behavior)
                data.show();
            }
        });
    };

    $.fn.modal.defaults = {
        backdrop: true,
        keyboard: true
    };
    $.fn.modal.Constructor = Modal;

    /* MODAL DATA-API */
    $(function () {
        $('body').on('click.modal.data-api', '[data-toggle="modal"]', function (e) {
            var $this = $(this);
            var href;
            var $target = $($this.attr('data-target') || (href = $this.attr('href')) && href.replace(/.*(?=#[^\s]+$)/, '')); // strip for ie7
            var option = $target.data('modal') ? 'toggle' : $.extend({}, $target.data(), $this.data());

            e.preventDefault();
            $target.modal(option);
        });
    });


    // --- 8. TOOLTIP PUBLIC CLASS DEFINITION (bootstrap-tooltip.js) ---
    var Tooltip = function (element, options) {
        this.init('tooltip', element, options);
    };

    Tooltip.prototype = {
        constructor: Tooltip,

        init: function (type, element, options) {
            var eventIn;
            var eventOut;

            this.type = type;
            this.$element = $(element);
            this.options = this.getOptions(options);
            this.enabled = true;

            if (this.options.trigger != 'manual') {
                eventIn = this.options.trigger == 'hover' ? 'mouseenter' : 'focus';
                eventOut = this.options.trigger == 'hover' ? 'mouseleave' : 'blur';
                this.$element.on(eventIn, this.options.selector, $.proxy(this.enter, this));
                this.$element.on(eventOut, this.options.selector, $.proxy(this.leave, this));
            }

            this.options.selector ?
            (this._options = $.extend({}, this.options, { trigger: 'manual', selector: '' })) :
            this.fixTitle();
        },

        getOptions: function (options) {
            options = $.extend({}, $.fn[this.type].defaults, options, this.$element.data());

            if (options.delay && typeof options.delay == 'number') {
                options.delay = {
                    show: options.delay,
                    hide: options.delay
                };
            }

            return options;
        },

        enter: function (e) {
            var self = $(e.currentTarget)[this.type](this._options).data(this.type);

            if (!self.options.delay || !self.options.delay.show) {
                self.show();
            } else {
                self.hoverState = 'in';
                setTimeout(function () {
                    if (self.hoverState == 'in') {
                        self.show();
                    }
                }, self.options.delay.show);
            }
        },

        leave: function (e) {
            var self = $(e.currentTarget)[this.type](this._options).data(this.type);

            if (!self.options.delay || !self.options.delay.hide) {
                self.hide();
            } else {
                self.hoverState = 'out';
                setTimeout(function () {
                    if (self.hoverState == 'out') {
                        self.hide();
                    }
                }, self.options.delay.hide);
            }
        },

        show: function () {
            var $tip;
            var inside;
            var pos;
            var actualWidth;
            var actualHeight;
            var placement;
            var tp;

            if (this.hasContent() && this.enabled) {
                $tip = this.tip();
                this.setContent();

                if (this.options.animation) {
                    $tip.addClass('fade');
                }

                placement = typeof this.options.placement == 'function' ?
                this.options.placement.call(this, $tip[0], this.$element[0]) :
                this.options.placement;

                inside = /in/.test(placement);

                $tip
                    .remove()
                    .css({ top: 0, left: 0, display: 'block' })
                    .appendTo(inside ? this.$element : document.body);

                pos = this.getPosition(inside);

                actualWidth = $tip[0].offsetWidth;
                actualHeight = $tip[0].offsetHeight;

                switch (inside ? placement.split(' ')[1] : placement) {
                    case 'bottom':
                        tp = { top: pos.top + pos.height, left: pos.left + pos.width / 2 - actualWidth / 2 };
                        break;
                    case 'top':
                        tp = { top: pos.top - actualHeight, left: pos.left + pos.width / 2 - actualWidth / 2 };
                        break;
                    case 'left':
                        tp = { top: pos.top + pos.height / 2 - actualHeight / 2, left: pos.left - actualWidth };
                        break;
                    case 'right':
                        tp = { top: pos.top + pos.height / 2 - actualHeight / 2, left: pos.left + pos.width };
                        break;
                }

                $tip
                    .css(tp)
                    .addClass(placement)
                    .addClass('in');
            }
        },

        setContent: function () {
            var $tip = this.tip();
            $tip.find('.tooltip-inner').html(this.getTitle());
            $tip.removeClass('fade in top bottom left right');
        },

        hide: function () {
            var that = this;
            var $tip = this.tip();

            $tip.removeClass('in');

            function removeWithAnimation() {
                var timeout = setTimeout(function () {
                    $tip.off($.support.transition.end).remove();
                }, 500);

                $tip.one($.support.transition.end, function () {
                    clearTimeout(timeout);
                    $tip.remove();
                });
            }

            $.support.transition && $tip.hasClass('fade') ?
            removeWithAnimation() :
            $tip.remove();
        },

        fixTitle: function () {
            var $e = this.$element;
            if ($e.attr('title') || typeof ($e.attr('data-original-title')) != 'string') {
                $e.attr('data-original-title', $e.attr('title') || '').removeAttr('title');
            }
        },

        hasContent: function () {
            return this.getTitle();
        },

        getPosition: function (inside) {
            return $.extend({}, (inside ? { top: 0, left: 0 } : this.$element.offset()), {
                width: this.$element[0].offsetWidth,
                height: this.$element[0].offsetHeight
            });
        },

        getTitle: function () {
            var title;
            var $e = this.$element;
            var o = this.options;

            title = $e.attr('data-original-title') || (typeof o.title == 'function' ? o.title.call($e[0]) : o.title);

            title = title.toString().replace(/(^\s*|\s*$)/, "");

            return title;
        },

        tip: function () {
            return this.$tip = this.$tip || $(this.options.template);
        },

        validate: function () {
            if (!this.$element[0].parentNode) {
                this.hide();
                this.$element = null;
                this.options = null;
            }
        },

        enable: function () {
            this.enabled = true;
        },

        disable: function () {
            this.enabled = false;
        },

        toggleEnabled: function () {
            this.enabled = !this.enabled;
        },

        toggle: function () {
            this[this.tip().hasClass('in') ? 'hide' : 'show']();
        }
    };

    /* TOOLTIP PLUGIN DEFINITION */
    $.fn.tooltip = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('tooltip');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('tooltip', (data = new Tooltip(this, options)));
            if (typeof option == 'string') data[option]();
        });
    };
    $.fn.tooltip.Constructor = Tooltip;

    $.fn.tooltip.defaults = {
        animation: true,
        delay: 0,
        selector: false,
        placement: 'top',
        trigger: 'hover',
        title: '',
        template: '<div class="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>'
    };


    // --- 9. POPOVER CLASS DEFINITION (bootstrap-popover.js) ---
    var Popover = function (element, options) {
        this.init('popover', element, options);
    };

    /* NOTE: POPOVER EXTENDS BOOTSTRAP-TOOLTIP.js */
    Popover.prototype = $.extend({}, $.fn.tooltip.Constructor.prototype, {
        constructor: Popover,

        setContent: function () {
            var $tip = this.tip();
            var title = this.getTitle();
            var content = this.getContent();

            $tip.find('.popover-title')[$.type(title) == 'object' ? 'append' : 'html'](title);
            $tip.find('.popover-content > *')[$.type(content) == 'object' ? 'append' : 'html'](content);

            $tip.removeClass('fade top bottom left right in');
        },

        hasContent: function () {
            return this.getTitle() || this.getContent();
        },

        getContent: function () {
            var content;
            var $e = this.$element;
            var o = this.options;

            content = $e.attr('data-content') || (typeof o.content == 'function' ? o.content.call($e[0]) : o.content);

            content = content.toString().replace(/(^\s*|\s*$)/, "");

            return content;
        },

        tip: function () {
            if (!this.$tip) {
                this.$tip = $(this.options.template);
            }
            return this.$tip;
        }
    });

    /* POPOVER PLUGIN DEFINITION */
    $.fn.popover = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('popover');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('popover', (data = new Popover(this, options)));
            if (typeof option == 'string') data[option]();
        });
    };
    $.fn.popover.Constructor = Popover;

    $.fn.popover.defaults = $.extend({}, $.fn.tooltip.defaults, {
        placement: 'right',
        content: '',
        template: '<div class="popover"><div class="arrow"></div><div class="popover-inner"><h3 class="popover-title"></h3><div class="popover-content"><p></p></div></div></div>'
    });


    // --- 10. SCROLLSPY CLASS DEFINITION (bootstrap-scrollspy.js) ---
    function ScrollSpy(element, options) {
        var process = $.proxy(this.process, this);
        var $element = $(element).is('body') ? $(window) : $(element);
        var href;
        this.options = $.extend({}, $.fn.scrollspy.defaults, options);
        this.$scrollElement = $element.on('scroll.scroll.data-api', process);
        this.selector = (this.options.target || ((href = $(element).attr('href')) && href.replace(/.*(?=#[^\s]+$)/, '')) // strip for ie7
        || '') + ' .nav li > a';
        this.$body = $('body').on('click.scroll.data-api', this.selector, process);
        this.refresh();
        this.process();
    }

    ScrollSpy.prototype = {
        constructor: ScrollSpy,

        refresh: function () {
            this.targets = this.$body
                .find(this.selector)
                .map(function () {
                    var href = $(this).attr('href');
                    return /^#\w/.test(href) && $(href).length ? href : null;
                });

            this.offsets = $.map(this.targets, function (id) {
                return $(id).position().top;
            });
        },

        process: function () {
            var scrollTop = this.$scrollElement.scrollTop() + this.options.offset;
            var offsets = this.offsets;
            var targets = this.targets;
            var activeTarget = this.activeTarget;
            var i;

            for (i = offsets.length; i--;) {
                activeTarget != targets[i] &&
                scrollTop >= offsets[i] &&
                (!offsets[i + 1] || scrollTop <= offsets[i + 1]) &&
                this.activate(targets[i]);
            }
        },

        activate: function (target) {
            var active;

            this.activeTarget = target;

            this.$body
                .find(this.selector).parent('.active')
                .removeClass('active');

            active = this.$body
                .find(this.selector + '[href="' + target + '"]')
                .parent('li')
                .addClass('active');

            if (active.parent('.dropdown-menu')) {
                active.closest('li.dropdown').addClass('active');
            }
        }
    };

    /* SCROLLSPY PLUGIN DEFINITION */
    $.fn.scrollspy = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('scrollspy');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('scrollspy', (data = new ScrollSpy(this, options)));
            if (typeof option == 'string') data[option]();
        });
    };
    $.fn.scrollspy.Constructor = ScrollSpy;
    $.fn.scrollspy.defaults = {
        offset: 10
    };

    /* SCROLLSPY DATA-API */
    $(function () {
        $('[data-spy="scroll"]').each(function () {
            var $spy = $(this);
            $spy.scrollspy($spy.data());
        });
    });


    // --- 11. TAB CLASS DEFINITION (bootstrap-tab.js) ---
    var Tab = function (element) {
        this.element = $(element);
    };

    Tab.prototype = {
        constructor: Tab,

        show: function () {
            var $this = this.element;
            var $ul = $this.closest('ul:not(.dropdown-menu)');
            var selector = $this.attr('data-target');
            var previous;
            var $target;

            if (!selector) {
                selector = $this.attr('href');
                selector = selector && selector.replace(/.*(?=#[^\s]*$)/, ''); // strip for ie7
            }

            if ($this.parent('li').hasClass('active')) return;

            previous = $ul.find('.active a').last()[0];

            $this.trigger({
                type: 'show',
                relatedTarget: previous
            });

            $target = $(selector);

            this.activate($this.parent('li'), $ul);
            this.activate($target, $target.parent(), function () {
                $this.trigger({
                    type: 'shown',
                    relatedTarget: previous
                });
            });
        },

        activate: function (element, container, callback) {
            var $active = container.find('> .active');
            var transition = callback && $.support.transition && $active.hasClass('fade');

            function next() {
                $active
                    .removeClass('active')
                    .find('> .dropdown-menu > .active')
                    .removeClass('active');

                element.addClass('active');

                if (transition) {
                    element[0].offsetWidth; // reflow for transition
                    element.addClass('in');
                } else {
                    element.removeClass('fade');
                }

                if (element.parent('.dropdown-menu')) {
                    element.closest('li.dropdown').addClass('active');
                }

                callback && callback();
            }

            transition ?
            $active.one($.support.transition.end, next) :
            next();

            $active.removeClass('in');
        }
    };

    /* TAB PLUGIN DEFINITION */
    $.fn.tab = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('tab');
            if (!data) $this.data('tab', (data = new Tab(this)));
            if (typeof option == 'string') data[option]();
        });
    };
    $.fn.tab.Constructor = Tab;

    /* TAB DATA-API */
    $(function () {
        $('body').on('click.tab.data-api', '[data-toggle="tab"], [data-toggle="pill"]', function (e) {
            e.preventDefault();
            $(this).tab('show');
        });
    });


    // --- 12. TYPEAHEAD CLASS DEFINITION (bootstrap-typeahead.js) ---
    var Typeahead = function (element, options) {
        this.$element = $(element);
        this.options = $.extend({}, $.fn.typeahead.defaults, options);
        this.matcher = this.options.matcher || this.matcher;
        this.sorter = this.options.sorter || this.sorter;
        this.highlighter = this.options.highlighter || this.highlighter;
        this.$menu = $(this.options.menu).appendTo('body');
        this.source = this.options.source;
        this.shown = false;
        this.listen();
    };

    Typeahead.prototype = {
        constructor: Typeahead,

        select: function () {
            var val = this.$menu.find('.active').attr('data-value');
            this.$element.val(val);
            return this.hide();
        },

        show: function () {
            var pos = $.extend({}, this.$element.offset(), {
                height: this.$element[0].offsetHeight
            });

            this.$menu.css({
                top: pos.top + pos.height,
                left: pos.left
            });

            this.$menu.show();
            this.shown = true;
            return this;
        },

        hide: function () {
            this.$menu.hide();
            this.shown = false;
            return this;
        },

        lookup: function () {
            var that = this;
            var items;

            this.query = this.$element.val();

            if (!this.query) {
                return this.shown ? this.hide() : this;
            }

            items = $.grep(this.source, function (item) {
                if (that.matcher(item)) {
                    return item;
                }
            });

            items = this.sorter(items);

            if (!items.length) {
                return this.shown ? this.hide() : this;
            }

            return this.render(items.slice(0, this.options.items)).show();
        },

        matcher: function (item) {
            return ~item.toLowerCase().indexOf(this.query.toLowerCase());
        },

        sorter: function (items) {
            var beginswith = [];
            var caseSensitive = [];
            var caseInsensitive = [];
            var item;

            while (item = items.shift()) {
                if (!item.toLowerCase().indexOf(this.query.toLowerCase())) {
                    beginswith.push(item);
                } else if (~item.indexOf(this.query)) {
                    caseSensitive.push(item);
                } else { // FIXED: Corrected logic/syntax error
                    caseInsensitive.push(item);
                }
            }

            return beginswith.concat(caseSensitive, caseInsensitive);
        },

        highlighter: function (item) {
            return item.replace(
                new RegExp('(' + this.query + ')', 'ig'),
                function ($1, match) {
                    return '<strong>' + match + '</strong>';
                }
            );
        },

        render: function (items) {
            var that = this;

            items = $(items).map(function (i, item) {
                i = $(that.options.item).attr('data-value', item);
                i.find('a').html(that.highlighter(item));
                return i[0];
            });

            items.first().addClass('active');
            this.$menu.html(items);
            return this;
        },

        next: function () {
            var active = this.$menu.find('.active').removeClass('active');
            var next = active.next();

            if (!next.length) {
                next = $(this.$menu.find('li')[0]);
            }

            next.addClass('active');
        },

        prev: function () {
            var active = this.$menu.find('.active').removeClass('active');
            var prev = active.prev();

            if (!prev.length) {
                prev = this.$menu.find('li').last();
            }

            prev.addClass('active');
        },

        listen: function () {
            this.$element
                .on('blur', $.proxy(this.blur, this))
                .on('keypress', $.proxy(this.keypress, this))
                .on('keyup', $.proxy(this.keyup, this));

            // REMOVED: $.browser.webkit || $.browser.msie check (deprecated, breaks modern jQuery)
            // .on('keydown', $.proxy(this.keypress, this)) 
            
            this.$menu
                .on('click', $.proxy(this.click, this))
                .on('mouseenter', 'li', $.proxy(this.mouseenter, this));
        },

        keyup: function (e) {
            e.stopPropagation();
            e.preventDefault();

            switch (e.keyCode) {
                case 40: // down arrow
                    this.next();
                    break;
                case 38: // up arrow
                    this.prev();
                    break;

                case 9: // tab
                case 13: // enter
                    if (!this.shown) return;
                    this.select();
                    break;

                case 27: // escape
                    this.hide();
                    break;

                default:
                    this.lookup();
            }
        },

        keypress: function (e) {
            e.stopPropagation();
            if (!this.shown) return; // FIXED: Removed unnecessary 'return' and switched to clean logic

            switch (e.keyCode) {
                case 9: // tab
                case 13: // enter
                case 27: // escape
                    e.preventDefault();
                    break;

                case 38: // up arrow
                    e.preventDefault();
                    this.prev();
                    break;

                case 40: // down arrow
                    e.preventDefault();
                    this.next();
                    break;
            }
        },

        blur: function (e) {
            var that = this;
            e.stopPropagation();
            e.preventDefault();
            setTimeout(function () {
                that.hide();
            }, 150);
        },

        click: function (e) {
            e.stopPropagation();
            e.preventDefault();
            this.select();
        },

        mouseenter: function (e) {
            this.$menu.find('.active').removeClass('active');
            $(e.currentTarget).addClass('active');
        }
    };

    /* TYPEAHEAD PLUGIN DEFINITION */
    $.fn.typeahead = function (option) {
        return this.each(function () {
            var $this = $(this);
            var data = $this.data('typeahead');
            var options = typeof option == 'object' && option;
            if (!data) $this.data('typeahead', (data = new Typeahead(this, options)));
            if (typeof option == 'string') data[option]();
        });
    };

    $.fn.typeahead.defaults = {
        source: [],
        items: 8,
        menu: '<ul class="typeahead dropdown-menu"></ul>',
        item: '<li><a href="#"></a></li>'
    };
    $.fn.typeahead.Constructor = Typeahead;

    /* TYPEAHEAD DATA-API */
    $(function () {
        $('body').on('focus.typeahead.data-api', '[data-provide="typeahead"]', function (e) {
            var $this = $(this);
            if ($this.data('typeahead')) return; // FIXED: Prevent re-initialization error
            e.preventDefault();
            $this.typeahead($this.data());
        });
    });

}(window.jQuery);
