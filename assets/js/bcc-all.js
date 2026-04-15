/* global jQuery, bcc_ajax */
(function($) {
    'use strict';

    /* ======================================================
       TOAST NOTIFICATION SYSTEM
    ====================================================== */
    window.bccToast = function(msg, type = "success", duration = 2200) {
        let $t = $(".bcc-toast");

        if (!$t.length) {
            $("body").append('<div class="bcc-toast"></div>');
            $t = $(".bcc-toast");
        }

        if ($t.data("timeout")) {
            clearTimeout($t.data("timeout"));
        }

        $t.removeClass("show bcc-toast-error").text(msg);

        if (type === "error") {
            $t.addClass("bcc-toast-error");
        }

        void $t[0].offsetWidth;
        $t.addClass("show");

        const timeout = setTimeout(() => {
            $t.removeClass("show");
        }, duration);

        $t.data("timeout", timeout);
    };

    /* ======================================================
       UTILITY FUNCTIONS
    ====================================================== */
    
    window.bccParseOptions = function(str) {
        const map = {};
        if (!str) return map;

        str.split(",").forEach(pair => {
            const parts = pair.split(":");
            if (parts.length >= 2) {
                const key = parts.shift().trim();
                const val = parts.join(":").trim();
                if (key && val) {
                    map[key] = val;
                }
            }
        });
        return map;
    };

    window.bccEscapeHtml = function(str) {
        if (str === null || str === undefined) return "";
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    };

    window.bccIsUrlField = function(name) {
        return name && (name.includes("url") || name.includes("link"));
    };

    window.bccBuildSelect = function(options, selected) {
        const map = window.bccParseOptions(options);
        if (Object.keys(map).length === 0) {
            return '<input class="bcc-inline-input" type="text" value="">';
        }

        let html = '<select class="bcc-inline-input">';
        
        if (!selected && !map.hasOwnProperty('')) {
            html += '<option value="">— Select —</option>';
        }

        Object.keys(map).forEach(key => {
            const sel = String(key) === String(selected) ? " selected" : "";
            html += `<option value="${window.bccEscapeHtml(key)}"${sel}>${window.bccEscapeHtml(map[key])}</option>`;
        });

        html += "</select>";
        return html;
    };

    window.bccShowLoading = function($el, show = true) {
        if (show) {
            $el.addClass("bcc-loading").prop("disabled", true);
        } else {
            $el.removeClass("bcc-loading").prop("disabled", false);
        }
    };

    window.bccPost = function(action, data) {
        return $.post(bcc_ajax.ajax_url, $.extend({
            action: action,
            nonce: bcc_ajax.nonce
        }, data));
    };

    /* ======================================================
       GALLERY SLIDER
    ====================================================== */
    
    window.initGallerySlider = function($wrapper) {
        if (!$wrapper || !$wrapper.length) {
            $wrapper = $('.bcc-gallery-slider-wrapper');
        }
        
        $wrapper.each(function() {
            const $wrap = $(this);
            const $slider = $wrap.find('.bcc-gallery-slider');
            const $items = $slider.find('.bcc-slider-item');
            const $prev = $wrap.find('.bcc-slider-prev');
            const $next = $wrap.find('.bcc-slider-next');
            const $dots = $wrap.find('.bcc-slider-dot');
            const $counter = $wrap.find('.bcc-slider-counter');
            
            if ($items.length === 0) return;
            
            let currentIndex = 0;
            
            // Find active index
            $items.each(function(idx) {
                if ($(this).hasClass('active')) {
                    currentIndex = idx;
                }
            });
            
            function showSlide(index) {
                if (index < 0) index = $items.length - 1;
                if (index >= $items.length) index = 0;
                
                $items.removeClass('active');
                $items.eq(index).addClass('active');
                
                $dots.removeClass('active');
                $dots.eq(index).addClass('active');
                
                if ($counter.length) {
                    $counter.text((index + 1) + ' / ' + $items.length);
                }
                
                currentIndex = index;
            }
            
            $prev.off('click').on('click', function(e) {
                e.preventDefault();
                showSlide(currentIndex - 1);
            });
            
            $next.off('click').on('click', function(e) {
                e.preventDefault();
                showSlide(currentIndex + 1);
            });
            
            $dots.off('click').on('click', function() {
                const index = $(this).data('index');
                showSlide(index);
            });

            // Touch/swipe support for mobile
            (function() {
                let touchStartX = 0;
                let touchStartY = 0;

                $wrap[0].addEventListener('touchstart', function(e) {
                    touchStartX = e.touches[0].clientX;
                    touchStartY = e.touches[0].clientY;
                }, { passive: true });

                $wrap[0].addEventListener('touchend', function(e) {
                    const dx = e.changedTouches[0].clientX - touchStartX;
                    const dy = e.changedTouches[0].clientY - touchStartY;
                    // Only trigger on a dominant horizontal swipe of 40+ px
                    if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) {
                        showSlide(dx < 0 ? currentIndex + 1 : currentIndex - 1);
                    }
                }, { passive: true });
            })();
        });
    };

    /* ======================================================
       GALLERY CORE
    ====================================================== */

    function initGallery($container) {
        const postId = parseInt($container.data('post'), 10);
        const row    = parseInt($container.data('row'), 10);
        
        initUpload($container, postId, row);
        initDelete($container, postId, row);
        initDragSort($container, postId, row);
        initAutoLazyLoad($container, postId, row);
        initBulkActions($container);
    }

    function initUpload($container, postId, row) {
        const $input  = $container.find('.bcc-gallery-file-input');
        const $button = $container.find('.bcc-gallery-upload');

        if (!$input.length || !$button.length) return;

        $button.off('click.bcc').on('click.bcc', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $input.trigger('click');
        });

        $input.off('change.bcc').on('change.bcc', function() {
            if (!this.files || !this.files.length) return;

            const formData = new FormData();
            formData.append('action', 'bcc_upload_gallery_images');
            formData.append('nonce', bcc_ajax.nonce);
            formData.append('post_id', postId);
            formData.append('row', row);

            for (let i = 0; i < this.files.length; i++) {
                formData.append('files[]', this.files[i]);
            }

            const originalText = $button.text();
            $button.prop('disabled', true).text('Uploading...');

            $.ajax({
                url: bcc_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false
            })
            .done(function(res) {
                if (!res || !res.success) {
                    window.bccToast(res?.data?.message || 'Upload failed', 'error');
                    return;
                }

                if (res.data.images && res.data.images.length) {
                    res.data.images.forEach(function(img) {
                        appendThumb($container, img);
                    });
                    
                    refreshMainSlider($container, postId, row);
                    updateCount($container);
                    initDragSort($container, postId, row);
                    window.bccToast(res.data.images.length + ' image(s) uploaded');
                }
            })
            .fail(function() {
                window.bccToast('Upload failed - server error', 'error');
            })
            .always(function() {
                $button.prop('disabled', false).text(originalText);
            });

            this.value = '';
        });
    }

    function initDelete($container, postId, row) {
        $container.off('click', '.bcc-gallery-remove');
        
        $container.on('click', '.bcc-gallery-remove', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $btn = $(this);
            const $thumb = $btn.closest('.bcc-gallery-thumb-wrapper');
            const imageId = parseInt($thumb.data('id'), 10);
            const containerPostId = parseInt($container.data('post'), 10);
            const containerRow = parseInt($container.data('row'), 10);
            
            if (!imageId || isNaN(imageId) || !containerPostId || isNaN(containerPostId)) {
                window.bccToast('Invalid image data', 'error');
                return;
            }
            
            if (!confirm('Delete this image?')) return;

            const originalHtml = $btn.html();
            $btn.html('...').css('opacity', '0.5');

            window.bccPost('bcc_delete_gallery_image', {
                post_id: containerPostId,
                row: containerRow,
                image_id: imageId
            })
            .done(function(res) {
                if (res && res.success) {
                    $thumb.fadeOut(300, function() {
                        $(this).remove();
                        refreshMainSlider($container, containerPostId, containerRow);
                        updateCount($container);
                        $(document).trigger('bcc-gallery-updated');
                        window.bccToast('Image deleted');
                    });
                } else {
                    window.bccToast(res?.data?.message || 'Delete failed', 'error');
                    $btn.html(originalHtml).css('opacity', '1');
                }
            })
            .fail(function() {
                window.bccToast('Delete failed - server error', 'error');
                $btn.html(originalHtml).css('opacity', '1');
            });
        });
    }

    function initDragSort($container, postId, row) {
        const $thumbs = $container.find('.bcc-gallery-thumbnails');
        if (!$thumbs.length) return;

        let dragged = null;
        let dragStarted = false;

        $thumbs.find('.bcc-gallery-thumb-wrapper')
            .removeAttr('draggable')
            .off('.drag');

        $thumbs.find('.bcc-gallery-thumb-wrapper').each(function() {
            const $this = $(this);
            
            $this.find('.bcc-gallery-remove').on('mousedown.drag', function(e) {
                e.stopPropagation();
            });
            
            $this.attr('draggable', true);
        });

        $thumbs.on('dragstart.drag', '.bcc-gallery-thumb-wrapper', function(e) {
            dragged = this;
            dragStarted = true;
            $(this).addClass('is-dragging');
            
            e.originalEvent.dataTransfer.setData('text/plain', $(this).data('id'));
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            
            const img = new Image();
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAUEBAAAACwAAAAAAQABAAACAkQBADs=';
            e.originalEvent.dataTransfer.setDragImage(img, 0, 0);
        });

        $thumbs.on('dragover.drag', '.bcc-gallery-thumb-wrapper', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (!dragged || dragged === this || !dragStarted) return;

            const rect = this.getBoundingClientRect();
            const after = (e.originalEvent.clientX - rect.left) > rect.width / 2;

            if (after) {
                this.parentNode.insertBefore(dragged, this.nextSibling);
            } else {
                this.parentNode.insertBefore(dragged, this);
            }
        });

        $thumbs.on('dragend.drag', '.bcc-gallery-thumb-wrapper', function() {
            $(this).removeClass('is-dragging');
            
            if (dragged && dragStarted) {
                setTimeout(() => {
                    saveOrder($container, postId, row);
                }, 50);
            }
            
            dragged = null;
            dragStarted = false;
        });

        $thumbs.on('dragover.drag dragenter.drag dragleave.drag drop.drag', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
    }

    function saveOrder($container, postId, row) {
        const ids = [];

        $container.find('.bcc-gallery-thumb-wrapper').each(function() {
            const id = parseInt($(this).data('id'), 10);
            if (id) ids.push(id);
        });

        if (!ids.length) return;

        window.bccPost('bcc_gallery_reorder_images', {
            post_id: postId,
            row: row,
            order: ids
        }).fail(function() {
            window.bccToast('Failed to save image order', 'error');
        });
    }

    function initAutoLazyLoad($container, postId, row) {
        const $strip = $container.find('.bcc-gallery-thumbnails');

        $strip.off('scroll.bcc').on('scroll.bcc', function() {
            const total = parseInt($strip.data('total'), 10);
            const page = parseInt($strip.data('page') || 1, 10);
            const shown = $container.find('.bcc-gallery-thumb-wrapper').length;

            if (shown >= total) return;

            const nearEnd = $strip[0].scrollLeft + $strip.outerWidth() >= $strip[0].scrollWidth - 200;
            if (!nearEnd) return;

            if ($strip.data('loading')) return;
            $strip.data('loading', true);

            window.bccPost('bcc_gallery_list_images', {
                post_id: postId,
                row: row,
                page: page + 1,
                per_page: 12
            }).done(function(res) {
                if (!res || !res.success) return;

                $strip.data('page', res.data.page);

                res.data.items.forEach(function(img) {
                    appendThumb($container, img);
                });

                initDragSort($container, postId, row);
            }).always(function() {
                $strip.data('loading', false);
            });
        });
    }

    function initBulkActions($container) {
        $container.off('click.bcc', '.bcc-bulk-select').on('click.bcc', '.bcc-bulk-select', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const $checkboxes = $container.find('.bcc-thumb-select');
            
            const allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
            
            $checkboxes.prop('checked', !allChecked);
            
            if (!allChecked) {
                $container.find('.bcc-gallery-thumb-wrapper').addClass('bcc-gallery-thumb-selecting');
            } else {
                $container.find('.bcc-gallery-thumb-wrapper').removeClass('bcc-gallery-thumb-selecting');
            }
            
            $btn.text(allChecked ? 'Select All' : 'Deselect All');
        });
        
        $container.off('click.bcc', '.bcc-bulk-delete').on('click.bcc', '.bcc-bulk-delete', function(e) {
            e.preventDefault();

            const $btn            = $(this);
            const postId          = parseInt($container.data('post'), 10);
            const row             = parseInt($container.data('row'), 10);
            const $selectedThumbs = $container.find('.bcc-thumb-select:checked').closest('.bcc-gallery-thumb-wrapper');

            if (!postId) {
                window.bccToast('Missing post ID', 'error');
                return;
            }

            if (!$selectedThumbs.length) {
                window.bccToast('No images selected', 'error');
                return;
            }

            if (!confirm('Delete ' + $selectedThumbs.length + ' selected image(s)?')) {
                return;
            }

            // Collect all IDs and send a single batch request
            const imageIds = [];
            $selectedThumbs.each(function() {
                const id = parseInt($(this).data('id'), 10);
                if (id) imageIds.push(id);
            });

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Deleting...');

            window.bccPost('bcc_bulk_delete_gallery_images', {
                post_id:   postId,
                row:       row,
                image_ids: imageIds
            })
            .done(function(res) {
                if (res && res.success) {
                    $selectedThumbs.fadeOut(250, function() { $(this).remove(); });
                    refreshMainSlider($container, postId, row);
                    updateCount($container);
                    $container.find('.bcc-gallery-thumb-wrapper').removeClass('bcc-gallery-thumb-selecting');

                    if (res.data.failed && res.data.failed.length) {
                        window.bccToast(res.data.failed.length + ' image(s) could not be deleted', 'error');
                    } else {
                        window.bccToast('Images deleted successfully');
                    }
                } else {
                    window.bccToast(res?.data?.message || 'Bulk delete failed', 'error');
                }
            })
            .fail(function() {
                window.bccToast('Bulk delete failed - server error', 'error');
            })
            .always(function() {
                $btn.prop('disabled', false).text('Delete Selected');
            });
        });
    }

    function appendThumb($container, img) {
        const html = `
            <div class="bcc-gallery-thumb-wrapper" draggable="true" data-id="${parseInt(img.id, 10) || 0}">
                <input type="checkbox" class="bcc-thumb-select">
                <img src="${window.bccEscapeHtml(img.thumbnail || img.url)}" loading="lazy" alt="">
                <span class="bcc-gallery-remove">×</span>
            </div>
        `;
        $container.find('.bcc-gallery-thumbnails').append(html);
        $(document).trigger('bcc-gallery-updated');
    }

    function updateCount($container) {
        const count = $container.find('.bcc-gallery-thumb-wrapper').length;
        $container.find('.bcc-gallery-count').text(count + ' image' + (count !== 1 ? 's' : ''));
    }

    function refreshMainSlider($container, postId, row) {
        window.bccPost('bcc_gallery_list_images', {
            post_id: postId,
            row: row,
            page: 1,
            per_page: 50
        }).done(function(res) {
            if (!res || !res.success) return;

            const images = res.data.items || [];
            const $sliderSection = $container.find('.bcc-gallery-slider-section');

            if (!$sliderSection.length) return;

            let sliderHtml = '<div class="bcc-gallery-slider-wrapper">';
            sliderHtml += '<div class="bcc-gallery-slider">';

            images.forEach(function(img, index) {
                const activeClass = index === 0 ? 'active' : '';
                sliderHtml += `<div class="bcc-slider-item ${activeClass}">`;
                sliderHtml += `<img src="${window.bccEscapeHtml(img.url)}" loading="lazy" alt="">`;
                sliderHtml += '</div>';
            });

            sliderHtml += '</div>';

            if (images.length > 0) {
                sliderHtml += '<div class="bcc-slider-controls">';
                sliderHtml += '<button type="button" class="bcc-slider-prev"><span class="dashicons dashicons-arrow-left-alt2"></span></button>';
                sliderHtml += '<div class="bcc-slider-dots">';
                images.forEach(function(img, index) {
                    const dotActive = index === 0 ? 'active' : '';
                    sliderHtml += `<span class="bcc-slider-dot ${dotActive}" data-index="${index}"></span>`;
                });
                sliderHtml += '</div>';
                sliderHtml += '<button type="button" class="bcc-slider-next"><span class="dashicons dashicons-arrow-right-alt2"></span></button>';
                sliderHtml += '</div>';
                sliderHtml += `<div class="bcc-slider-counter">1 / ${images.length}</div>`;
            }

            sliderHtml += '</div>';

            $sliderSection.html(sliderHtml);
            $(document).trigger('bcc-gallery-updated');
        });
    }

    /* ======================================================
       INLINE EDITING
    ====================================================== */

    function buildPayload($el, value) {
        const data = {
            action: "bcc_inline_save",
            nonce: bcc_ajax.nonce,
            post_id: $el.data("post"),
            field: $el.data("field"),
            type: $el.data("type") || "text",
            value: value
        };

        if (parseInt($el.data("repeater"), 10) === 1) {
            data.repeater = 1;
            data.row = $el.data("row");
            data.sub = $el.data("sub");
        }

        return data;
    }

    function startEdit($span) {
        if ($span.data("editing")) return;

        const originalValue = $span.attr("data-value") || "";
        const originalHtml = $span.html();
        const field = String($span.data("field") || "");
        const options = String($span.data("options") || "");
        const placeholder = $span.data("placeholder") || "Enter value...";
        const type = $span.data("type") || "text";

        const isSelect = $span.hasClass("bcc-inline-select");
        const isWysiwyg = type === "wysiwyg" || /<\/?[a-z][\s\S]*>/i.test(originalHtml);

        let inputHtml = "";

        if (isSelect) {
            inputHtml = window.bccBuildSelect(options, originalValue);
        } else if (isWysiwyg) {
            inputHtml = `<textarea class="bcc-inline-input" rows="4" placeholder="${window.bccEscapeHtml(placeholder)}">${window.bccEscapeHtml(originalValue)}</textarea>`;
        } else {
            const inputType = window.bccIsUrlField(field) ? "url" : "text";
            inputHtml = `<input class="bcc-inline-input" type="${inputType}" value="${window.bccEscapeHtml(originalValue)}" placeholder="${window.bccEscapeHtml(placeholder)}">`;
        }

        const controls = `
            <span class="bcc-inline-actions">
                <button type="button" class="bcc-inline-save button button-small">Save</button>
                <button type="button" class="bcc-inline-cancel button button-small">Cancel</button>
            </span>
        `;

        $span
            .data({
                editing: true,
                originalHtml: originalHtml,
                originalValue: originalValue
            })
            .addClass("is-editing")
            .html(inputHtml + controls);

        const $input = $span.find(".bcc-inline-input");
        $input.focus();
        if ($input.is("input") && $input.val()) {
            $input[0].setSelectionRange(0, $input.val().length);
        }

        $span.off(".bcc").on("click.bcc", ".bcc-inline-save", function (e) {
            e.preventDefault();
            doSave($span);
        });

        $span.on("click.bcc", ".bcc-inline-cancel", function (e) {
            e.preventDefault();
            cancelEdit($span);
        });

        $span.on("keydown.bcc", ".bcc-inline-input", function (e) {
            if (e.key === "Enter" && this.tagName !== "TEXTAREA") {
                e.preventDefault();
                doSave($span);
            } else if (e.key === "Escape") {
                e.preventDefault();
                cancelEdit($span);
            }
        });
    }

    function cancelEdit($span) {
        const originalHtml = $span.data("originalHtml") || "Update Now";
        $span
            .off(".bcc")
            .removeClass("is-editing")
            .removeData("editing originalHtml originalValue")
            .html(originalHtml);
    }

    function doSave($span) {
        let val = "";

        if ($span.find("select").length) {
            val = $span.find("select").val();
        } else {
            val = $span.find(".bcc-inline-input").val();
        }

        if (!$span.find("textarea").length) {
            val = val.trim();
        }

        const $saveBtn = $span.find(".bcc-inline-save");
        window.bccShowLoading($saveBtn, true);

        $.post(bcc_ajax.ajax_url, buildPayload($span, val))
            .done(function (res) {
                if (!res || res.success !== true) {
                    const msg = (typeof res?.data === 'string') ? res.data : (res?.data?.message || "Save failed");
                    window.bccToast(msg, "error");
                    cancelEdit($span);
                    return;
                }

                $span.attr("data-value", val);

                // Use the server-sanitized value for display
                var serverVal = res.data && res.data.value !== undefined ? res.data.value : val;

                if ($span.hasClass("bcc-inline-select")) {
                    const map = window.bccParseOptions($span.data("options") || "");
                    const label = map[serverVal] || serverVal || "—";
                    $span.text(label);
                } else {
                    if ($span.find("textarea").length || $span.data("type") === "wysiwyg") {
                        // Server value is sanitized with wp_kses_post on the PHP side.
                        // Belt-and-suspenders: sanitize client-side too.
                        var clean = typeof DOMPurify !== "undefined"
                            ? DOMPurify.sanitize(serverVal || "")
                            : $("<div>").text(serverVal || "").html();
                        $span.html(clean);
                    } else {
                        $span.text(serverVal || "—");
                    }
                }

                $span
                    .off(".bcc")
                    .removeClass("is-editing")
                    .removeData("editing originalHtml originalValue");

                window.bccToast("Saved successfully");
            })
            .fail(function () {
                window.bccToast("Save failed - server error", "error");
                cancelEdit($span);
            })
            .always(function () {
                window.bccShowLoading($saveBtn, false);
            });
    }

    /* ======================================================
       VISIBILITY POPOVER
    ====================================================== */

    const $pop = $("#bcc-visibility-popover");
    if ($pop.length) {
        $pop.appendTo("body").hide();
    }

    $(document).on("click", ".bcc-visibility-pill", function (e) {
        e.preventDefault();
        e.stopPropagation();

        const $pill = $(this);
        const pillOffset = $pill.offset();
        const pillHeight = $pill.outerHeight();
        
        window.bccVisContext = {
            post: $pill.data("post"),
            field: $pill.data("field"),
            button: $pill
        };

        const $pop = $("#bcc-visibility-popover");
        if (!$pop.length) return;

        const popWidth = $pop.outerWidth();
        const popHeight = $pop.outerHeight();
        const winWidth = $(window).width();
        const winHeight = $(window).height();
        const scrollTop = $(window).scrollTop();

        let left = pillOffset.left;
        let top = pillOffset.top + pillHeight + 5;

        if (left + popWidth > winWidth) {
            left = winWidth - popWidth - 10;
        }
        
        if (top + popHeight > winHeight + scrollTop) {
            top = pillOffset.top - popHeight - 5;
        }

        if (left < 0) left = 10;

        $pop.css({
            position: 'absolute',
            top: top + 'px',
            left: left + 'px',
            zIndex: 999999
        }).fadeIn(150);
    });

    $(document).on("click", ".bcc-vis-option", function (e) {
        e.stopPropagation();

        const value = $(this).data("value");
        if (!window.bccVisContext) return;

        const $pill = window.bccVisContext.button;
        $pill.addClass("bcc-loading");

        $.post(bcc_ajax.ajax_url, {
            action: "bcc_save_field_visibility",
            nonce: bcc_ajax.nonce,
            post_id: window.bccVisContext.post,
            field: window.bccVisContext.field,
            visibility: value
        })
        .done(function (res) {
            if (!res || res.success !== true) {
                window.bccToast(res?.data?.message || "Save failed", "error");
                return;
            }

            const labels = {
                public: "🌍 Public",
                members: "👥 Members",
                private: "🔒 Private"
            };

            $pill
                .text(labels[value])
                .removeClass("public members private")
                .addClass(value)
                .data("current", value);

            window.bccToast("Visibility updated");
        })
        .fail(function () {
            window.bccToast("Failed to update visibility", "error");
        })
        .always(function () {
            $pill.removeClass("bcc-loading");
            $("#bcc-visibility-popover").fadeOut(120);
        });
    });

    $(document).on("click", function (e) {
        const $pop = $("#bcc-visibility-popover");
        if ($pop.is(":visible") && !$(e.target).closest(".bcc-visibility-pill, #bcc-visibility-popover").length) {
            $pop.fadeOut(120);
        }
    });

    $(document).on("keydown", function (e) {
        if (e.key === "Escape") {
            $("#bcc-visibility-popover").fadeOut(120);
        }
    });

    /* ======================================================
       REPEATER ACTIONS
    ====================================================== */

    $(document).on('click', '.bcc-add-repeater', function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        const postId = $btn.data('post');
        const field = $btn.data('field');
        const originalText = $btn.text();
        
        if (!postId || !field) {
            window.bccToast('Missing data', 'error');
            return;
        }
        
        $btn.prop('disabled', true)
            .html('<span class="dashicons dashicons-update spinning"></span> Adding...');
        
        window.bccPost('bcc_inline_save', {
            post_id: postId,
            field: field,
            value: '',
            repeater: 1,
            row: -1,
            sub: 'add_new'
        })
        .done(function(response) {
            if (response && response.success) {
                window.bccToast('Item added successfully');
                $btn.html('<span class="dashicons dashicons-yes"></span> Added!');
                setTimeout(() => location.reload(), 1000);
            } else {
                window.bccToast(response?.data?.message || 'Failed to add item', 'error');
                $btn.prop('disabled', false).html(originalText);
            }
        })
        .fail(function() {
            window.bccToast('Error adding item', 'error');
            $btn.prop('disabled', false).html(originalText);
        });
    });

    /* ======================================================
       REPEATER ROW DELETE HANDLER
    ====================================================== */

    $(document).on('click', '.bcc-delete-repeater', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $btn = $(this);
        const postId = $btn.data('post');
        const field = $btn.data('field');
        const rowIndex = $btn.data('row');

        if (!postId || !field || rowIndex === undefined) {
            window.bccToast('Missing data', 'error');
            return;
        }
        
        if (!confirm('Delete this entire item?')) return;

        // Find the slide container
        const $slide = $btn.closest('.bcc-slide');
        if (!$slide.length) {
            window.bccToast('Error: Could not find row container', 'error');
            return;
        }

        // Show loading state
        const originalHtml = $btn.html();
        $btn.html('<span class="dashicons dashicons-update spinning"></span>').prop('disabled', true);

        // Make AJAX request to delete the entire row
        $.ajax({
            url: bcc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bcc_delete_repeater_row',
                nonce: bcc_ajax.nonce,
                post_id: postId,
                field: field,
                row: rowIndex
            },
            dataType: 'json'
        })
        .done(function(res) {
            if (res && res.success) {
                // Remove the entire slide with animation
                var $parent = $slide.parent();
                $slide.fadeOut(300, function() {
                    $(this).remove();

                    // Update data-row attributes for remaining rows (scoped to parent)
                    $parent.find('.bcc-slide').each(function(index) {
                        $(this).attr('data-row', index);
                        $(this).find('.bcc-delete-repeater').attr('data-row', index);
                    });
                    
                    window.bccToast('Item deleted successfully');
                    $(document).trigger('bcc-repeater-updated');
                });
            } else {
                window.bccToast(res?.data?.message || 'Delete failed', 'error');
                $btn.html(originalHtml).prop('disabled', false);
            }
        })
        .fail(function(xhr) {
            let errorMsg = 'Delete failed - server error';
            try {
                const resp = JSON.parse(xhr.responseText);
                errorMsg = resp?.data?.message || errorMsg;
            } catch(e) {}

            window.bccToast(errorMsg, 'error');
            $btn.html(originalHtml).prop('disabled', false);
        });
    });

    /* ======================================================
       INITIALIZATION
    ====================================================== */

    $(document).ready(function() {
        // Initialize galleries
        $('.bcc-gallery-container').each(function() {
            initGallery($(this));
        });

        // Initialize sliders
        window.initGallerySlider();

        // Initialize lightbox
        initLightbox();

        // Expose public API
        window.bccInlineEdit = {
            start: startEdit,
            cancel: cancelEdit,
            save: doSave
        };
    });

    // Re-initialize when new galleries are added
    $(document).on('bcc-gallery-updated', function() {
        window.initGallerySlider();
    });

    // Inline edit event handlers
    $(document).on("click", ".bcc-inline-text, .bcc-inline-select", function (e) {
        if ($(e.target).is("input, textarea, select, button, .button")) return;
        startEdit($(this));
    });

    $(document).on("click", ".bcc-inline-edit-btn", function (e) {
        e.preventDefault();
        startEdit($(this).siblings(".bcc-inline-text, .bcc-inline-select").first());
    });

    /* ======================================================
       GALLERY LIGHTBOX
    ====================================================== */

    let $bccLightbox = null;
    let lbImages     = [];
    let lbIndex      = 0;

    function initLightbox() {
        if ($('#bcc-lightbox').length) {
            $bccLightbox = $('#bcc-lightbox');
            return;
        }

        $('body').append(
            '<div id="bcc-lightbox" class="bcc-lightbox" role="dialog" aria-modal="true" aria-label="Image viewer" style="display:none;">' +
                '<div class="bcc-lightbox-backdrop"></div>' +
                '<button type="button" class="bcc-lightbox-close" aria-label="Close">&times;</button>' +
                '<button type="button" class="bcc-lightbox-prev" aria-label="Previous image">&#8249;</button>' +
                '<div class="bcc-lightbox-content"><img class="bcc-lightbox-img" src="" alt=""></div>' +
                '<button type="button" class="bcc-lightbox-next" aria-label="Next image">&#8250;</button>' +
                '<div class="bcc-lightbox-counter"></div>' +
            '</div>'
        );

        $bccLightbox = $('#bcc-lightbox');

        $bccLightbox.on('click', '.bcc-lightbox-backdrop, .bcc-lightbox-close', closeLightbox);

        $bccLightbox.on('click', '.bcc-lightbox-prev', function() {
            lbIndex = (lbIndex - 1 + lbImages.length) % lbImages.length;
            showLightboxSlide();
        });

        $bccLightbox.on('click', '.bcc-lightbox-next', function() {
            lbIndex = (lbIndex + 1) % lbImages.length;
            showLightboxSlide();
        });
    }

    function openLightbox(images, startIndex) {
        if (!$bccLightbox) initLightbox();
        lbImages = images;
        lbIndex  = Math.max(0, Math.min(startIndex, images.length - 1));
        showLightboxSlide();
        $bccLightbox.fadeIn(180);
        $('body').addClass('bcc-no-scroll');
    }

    function closeLightbox() {
        if ($bccLightbox) $bccLightbox.fadeOut(180);
        $('body').removeClass('bcc-no-scroll');
    }

    function showLightboxSlide() {
        if (!$bccLightbox || !lbImages.length) return;
        const single = lbImages.length <= 1;
        $bccLightbox.find('.bcc-lightbox-img').attr('src', lbImages[lbIndex]);
        $bccLightbox.find('.bcc-lightbox-counter').text((lbIndex + 1) + ' / ' + lbImages.length);
        $bccLightbox.find('.bcc-lightbox-prev, .bcc-lightbox-next').prop('disabled', single);
    }

    // Click any active slider image to open lightbox
    $(document).on('click', '.bcc-slider-item img', function() {
        const $allImgs = $(this).closest('.bcc-gallery-slider').find('.bcc-slider-item img');
        const images   = $allImgs.map(function() { return this.src; }).get();
        const clicked  = $allImgs.index(this);
        openLightbox(images, clicked >= 0 ? clicked : 0);
    });

    // Keyboard navigation for lightbox
    $(document).on('keydown.bcc-lightbox', function(e) {
        if (!$bccLightbox || !$bccLightbox.is(':visible')) return;
        if (e.key === 'Escape')     { closeLightbox(); }
        if (e.key === 'ArrowLeft')  { lbIndex = (lbIndex - 1 + lbImages.length) % lbImages.length; showLightboxSlide(); }
        if (e.key === 'ArrowRight') { lbIndex = (lbIndex + 1) % lbImages.length; showLightboxSlide(); }
    });

    /* ======================================================
       COPY TO CLIPBOARD
    ====================================================== */

    $(document).on('click', '.bcc-copy-btn', function(e) {
        e.stopPropagation();

        const $btn = $(this);
        const text = String($btn.data('copy') || '');
        if (!text) return;

        const onCopied = function() {
            $btn.addClass('bcc-copied').html('<span class="dashicons dashicons-yes"></span>');
            setTimeout(function() {
                $btn.removeClass('bcc-copied').html('<span class="dashicons dashicons-clipboard"></span>');
            }, 2000);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onCopied).catch(function() {
                window.bccToast('Copy failed', 'error');
            });
        } else {
            // Fallback for older browsers
            const $tmp = $('<textarea>').css({ position: 'fixed', opacity: 0 }).val(text).appendTo('body');
            $tmp[0].select();
            document.execCommand('copy');
            $tmp.remove();
            onCopied();
        }
    });

})(jQuery);
