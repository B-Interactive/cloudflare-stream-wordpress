! function(e) {
    function t(o) {
        if (r[o]) return r[o].exports;
        var n = r[o] = {
            i: o,
            l: !1,
            exports: {}
        };
        return e[o].call(n.exports, n, n.exports, t), n.l = !0, n.exports
    }
    var r = {};
    t.m = e, t.c = r, t.d = function(e, r, o) {
        t.o(e, r) || Object.defineProperty(e, r, {
            configurable: !1,
            enumerable: !0,
            get: o
        })
    }, t.n = function(e) {
        var r = e && e.__esModule ? function() {
            return e.default
        } : function() {
            return e
        };
        return t.d(r, "a", r), r
    }, t.o = function(e, t) {
        return Object.prototype.hasOwnProperty.call(e, t)
    }, t.p = "", t(t.s = 2)
}([function(e, t) {
    var r;
    r = function() {
        return this
    }();
    try {
        r = r || Function("return this")() || (0, eval)("this")
    } catch (e) {
        "object" === typeof window && (r = window)
    }
    e.exports = r
}, function(e, t, r) {
    "use strict";

    function o(e, t) {
        if (a) return localStorage.setItem(e, t)
    }

    function n(e) {
        if (a) return localStorage.getItem(e)
    }

    function i(e) {
        if (a) return localStorage.removeItem(e)
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    }), t.setItem = o, t.getItem = n, t.removeItem = i;
    var a = !1;
    try {
        a = "localStorage" in window;
        var s = "tusSupport";
        localStorage.setItem(s, localStorage.getItem(s))
    } catch (e) {
        if (e.code !== e.SECURITY_ERR && e.code !== e.QUOTA_EXCEEDED_ERR) throw e;
        a = !1
    }
    t.canStoreURLs = a
}, function(e, t, r) {
    "use strict";
    Object.defineProperty(t, "__esModule", {
        value: !0
    });
    var o = r(3),
        n = (r.n(o), r(4)),
        i = (r.n(n), r(5)),
        a = (r.n(i), r(6), r(25));
    r.n(a)
}, function(e, t) {
    cloudflareStream.media.model.Attachments = wp.media.model.Attachments.extend({
        initialize: function() {
            wp.media.model.Attachments.prototype.initialize.apply(this, arguments)
        },
        _requery: function(e) {
            var t = void 0;
            this.props.get("query") && (t = this.props.toJSON(), t.cache = !0 !== e, this.mirror(cloudflareStream.media.model.Query.get(this.props.toJSON())))
        }
    })
}, function(e, t) {
    cloudflareStream.media.model.Query = wp.media.model.Query.extend({
        initialize: function(e, t) {
            t = t || {}, wp.media.model.Query.prototype.initialize.apply(this, arguments), this.args = t.args || {}, this.args.posts_per_page = cloudflareStream.api.posts_per_page
        },
        sync: function(e, t, r) {
            if ("read" === e) {
                r = r || {}, r.context = this, r.data = _.extend(r.data || {}, {
                    action: "query-cloudflare-stream-attachments",
                    post_id: wp.media.model.settings.post.id,
                    nonce: cloudflareStream.nonce
                });
                var o = "";
                cloudflareStream.media.model.Attachments.all.models.length > 0 && (o = "&end=" + cloudflareStream.media.model.Attachments.all.models[cloudflareStream.media.model.Attachments.all.models.length - 1].attributes.modified.toISOString());
                var n = _.clone(this.args);
                return -1 !== n.posts_per_page && (n.paged = Math.floor(this.length / n.posts_per_page) + 1), r.data.query = "asc=false" + o, wp.media.ajax(r)
            }
            return (wp.media.model.Attachments.prototype.sync ? wp.media.model.Attachments.prototype : Backbone).sync.apply(this, arguments)
        }
    }, {
        get: function() {
            var e = [];
            return function(t, r) {
                var o = {},
                    n = cloudflareStream.media.model.Query.orderby,
                    i = cloudflareStream.media.model.Query.defaultProps,
                    a = void 0;
                return delete t.query, delete t.remotefilters, delete t.uioptions, _.defaults(t, i), t.order = t.order.toUpperCase(), "DESC" !== t.order && "ASC" !== t.order && (t.order = i.order.toUpperCase()), _.contains(n.allowed, t.orderby) || (t.orderby = i.orderby), _.each(t, function(e, t) {
                    _.isNull(e) || (o[cloudflareStream.media.model.Query.propmap[t] || t] = e)
                }), _.defaults(o, cloudflareStream.media.model.Query.defaultArgs), o.orderby = n.valuemap[t.orderby] || t.orderby, a = _.find(e, function(e) {
                    return _.isEqual(e.args, o)
                }), a || (a = new cloudflareStream.media.model.Query([], _.extend(r || {}, {
                    props: t,
                    args: o
                })), e.push(a)), a
            }
        }()
    })
}, function(e, t) {
    var r = wp.media.view.MediaFrame.Post,
        o = wp.media.controller.Library,
        n = wp.media.view.l10n;
    cloudflareStream.media.view.MediaFrame = r.extend({
        initialize: function(e) {
            this.select = e, _.defaults(this.options, {
                id: "cloudflare-stream",
                className: "cloudflare-stream-media-frame",
                title: "Cloudflare Stream Library",
                multiple: !1,
                editing: !1,
                state: "insert",
                metadata: {}
            }), r.prototype.initialize.apply(this, arguments)
        },
        createStates: function() {
            var e = this.options;
            this.states.add([new o({
                id: "insert",
                title: e.title,
                priority: 20,
                toolbar: "main-insert",
                menu: !1,
                filterable: !1,
                searchable: !1,
                date: !1,
                library: new cloudflareStream.media.model.Query(_.defaults(null, {
                    type: "video"
                }, e.library)),
                multiple: !!e.multiple && "reset",
                editable: !0,
                allowLocalEdits: !0,
                displaySettings: !1,
                displayUserSettings: !1
            })])
        },
        bindHandlers: function() {
            var e = void 0,
                t = void 0;
            r.prototype.bindHandlers.apply(this, arguments), this.on("activate", this.activate, this), t = _.find(this.counts, function(e) {
                return 0 === e.count
            }), "undefined" !== typeof t && this.listenTo(wp.media.model.Attachments.all, "change:type", this.mediaTypeCounts), this.on("toolbar:create:main-insert", this.createToolbar, this), this.on("selection:toggle", this.bindSidebarItems, this), e = {
                toolbar: {
                    "main-insert": "mainInsertToolbar"
                }
            }, _.each(e, function(e, t) {
                _.each(e, function(e, r) {
                    this.on(t + ":render:" + r, this[e], this)
                }, this)
            }, this)
        },
        bindSidebarItems: function() {
            jQuery(".delete-attachment").on("click", this, this.deleteAttachment), jQuery('label[data-setting="title"] input').on("change", this, this.updateAttachment)
        },
        deleteAttachment: function(e) {
            e.preventDefault(), e.stopPropagation();
            var t = e.data;
            if (window.confirm(n.warnDelete)) {
                var r = t.state(),
                    o = r.get("selection"),
                    i = o.first().toJSON();
                o.remove(i), r.trigger("delete", i).reset()
            }
        },
        updateAttachment: function(e) {
            e.preventDefault(), e.stopPropagation();
            var t = e.data,
                r = t.state(),
                o = r.get("selection"),
                n = o.first().toJSON(),
                i = jQuery('label[data-setting="title"] input').val(),
                a = {
                    uid: n.uid,
                    meta: {
                        name: i,
                        upload: n.cloudflare.meta.upload
                    }
                };
            jQuery(".media-sidebar .spinner").css("visibility", "visible"), jQuery.ajax({
                url: "https://api.cloudflare.com/client/v4/accounts/" + cloudflareStream.api.account + "/media/" + n.uid,
                method: "POST",
                contentType: "application/json; charset=utf-8",
                dataType: "json",
                data: JSON.stringify(a),
                headers: {
                    "X-Auth-Email": cloudflareStream.api.email,
                    "X-Auth-Key": cloudflareStream.api.key
                },
                success: function() {
                    o.models[0].set("filename", i), jQuery(".media-sidebar .spinner").css("visibility", "hidden")
                },
                error: function(e, t) {
                    console.error("Error: " + t)
                }
            })
        },
        browseRouter: function(e) {
            e.set({
                browse: {
                    text: this.options.title,
                    priority: 40
                }
            })
        },
        mainInsertToolbar: function(e) {
            var t = this;
            e.set("insert", {
                style: "primary",
                priority: 80,
                text: "Select",
                requires: {
                    selection: !0
                },
                click: function() {
                    var e = t.state(),
                        r = e.get("selection"),
                        o = r.first().toJSON();
                    t.select(o), t.close(), e.trigger("insert", r).reset()
                }
            })
        }
    })
}, function(e, t, r) {
    "use strict";
    var o = r(7),
        n = (r.n(o), r(8)),
        i = (r.n(n), r(9)),
        a = wp.i18n.__,
        s = wp.blocks.registerBlockType;
    cloudflareStream.icon = wp.element.createElement("svg", {
        width: 20,
        height: 20,
        viewBox: "0 0 68.66 49.14",
        className: "cls-1 dashicon"
    }, wp.element.createElement("path", {
        d: "M61.05,42.28H1.75A.76.76,0,0,1,1,41.52V1.73A.75.75,0,0,1,1.75,1h59.3a.75.75,0,0,1,.76.75V41.52A.76.76,0,0,1,61.05,42.28ZM2.51,40.77H60.3V2.49H2.51Z"
    }), wp.element.createElement("path", {
        d: "M45.6,26.09,31.44,17.91a1.17,1.17,0,0,0-1.19-.09,1.19,1.19,0,0,0-.51,1.07V35.25a1.17,1.17,0,0,0,.51,1.06.91.91,0,0,0,.48.13,1.41,1.41,0,0,0,.71-.21L45.6,28.05a1.05,1.05,0,0,0,0-2ZM65.13,48.14H7.86a2.52,2.52,0,0,1-2.52-2.52V7.86A2.52,2.52,0,0,1,7.86,5.34H65.13a2.52,2.52,0,0,1,2.53,2.52V45.62A2.52,2.52,0,0,1,65.13,48.14Zm-56.77-3H64.63V8.36H8.36Z"
    })), s("cloudflare-stream/block-video", {
        title: a("Cloudflare Stream Video"),
        icon: cloudflareStream.icon,
        render_callback: "cloudflare_stream_render_block",
        category: "embed",
        keywords: [a("Cloudflare"), a("Stream"), a("video")],
        attributes: {
            alignment: {
                type: "string"
            },
            uid: {
                type: "string",
                default: !1
            },
            fingerprint: {
                type: "string",
                default: !1
            },
            thumbnail: {
                type: "string",
                default: !1
            },
            autoplay: {
                type: "boolean",
                source: "attribute",
                selector: "stream",
                attribute: "autoplay",
                default: !1
            },
            loop: {
                type: "boolean",
                source: "attribute",
                selector: "stream",
                attribute: "loop",
                default: !1
            },
            muted: {
                type: "boolean",
                source: "attribute",
                selector: "stream",
                attribute: "muted",
                default: !1
            },
            controls: {
                type: "boolean",
                source: "attribute",
                selector: "stream",
                attribute: "controls",
                default: !0
            },
            transform: {
                type: "boolean",
                source: "attribute",
                selector: "stream",
                attribute: "transform",
                default: !1
            }
        },
        supports: {
            align: !0
        },
        edit: i.a,
        save: function(e) {
            var t = e.attributes,
                r = t.uid,
                o = t.controls,
                n = t.autoplay,
                i = t.loop,
                a = t.muted,
                s = t.className;
            return !1 !== r ? wp.element.createElement("figure", {
                className: s,
                key: r
            }, [wp.element.createElement("stream", {
                src: r,
                controls: o,
                autoplay: n,
                loop: i,
                muted: a
            }), wp.element.createElement("div", {
                className: "target"
            }), wp.element.createElement("script", {
                "data-cfasync": !1,
                defer: !0,
                type: "text/javascript",
                src: "https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=" + r
            })]) : wp.element.createElement("figure", {
                className: s
            })
        }
    })
}, function(e, t) {}, function(e, t) {}, function(e, t, r) {
    "use strict";

    function o(e, t, r) {
        return t in e ? Object.defineProperty(e, t, {
            value: r,
            enumerable: !0,
            configurable: !0,
            writable: !0
        }) : e[t] = r, e
    }

    function n(e, t) {
        if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function")
    }

    function i(e, t) {
        if (!e) throw new ReferenceError("this hasn't been initialised - super() hasn't been called");
        return !t || "object" !== typeof t && "function" !== typeof t ? e : t
    }

    function a(e, t) {
        if ("function" !== typeof t && null !== t) throw new TypeError("Super expression must either be null or a function, not " + typeof t);
        e.prototype = Object.create(t && t.prototype, {
            constructor: {
                value: e,
                enumerable: !1,
                writable: !0,
                configurable: !0
            }
        }), t && (Object.setPrototypeOf ? Object.setPrototypeOf(e, t) : e.__proto__ = t)
    }
    var s = r(10),
        l = r.n(s),
        u = function() {
            function e(e, t) {
                for (var r = 0; r < t.length; r++) {
                    var o = t[r];
                    o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, o.key, o)
                }
            }
            return function(t, r, o) {
                return r && e(t.prototype, r), o && e(t, o), t
            }
        }(),
        c = wp.i18n.__,
        d = wp.components,
        f = d.Disabled,
        p = d.IconButton,
        h = d.PanelBody,
        m = d.Toolbar,
        b = d.ToggleControl,
        y = d.withNotices,
        _ = d.Placeholder,
        g = d.FormFileUpload,
        v = wp.editor,
        w = v.BlockControls,
        E = v.InspectorControls,
        S = v.MediaUpload,
        C = wp.element,
        A = C.Fragment,
        j = C.Component,
        P = C.createRef,
        k = function(e) {
            function t(e) {
                n(this, t);
                var r = i(this, (t.__proto__ || Object.getPrototypeOf(t)).apply(this, arguments));
                return r.state = {
                    editing: !r.props.attributes.uid,
                    uploading: !1,
                    encoding: r.props.attributes.uid && !r.props.attributes.thumbnail,
                    resume: !0
                }, r.instanceId = e.clientId, r.controller = r, r.streamPlayer = P(), r.toggleAttribute = r.toggleAttribute.bind(r), r.open = r.open.bind(r), r.select = r.select.bind(r), r.mediaFrame = new cloudflareStream.media.view.MediaFrame(r.select), r.encodingPoller = !1, r
            }
            return a(t, e), u(t, [{
                key: "componentDidMount",
                value: function() {
                    var e = this.props.attributes;
                    !1 !== e.uid && !1 === e.thumbnail ? this.switchToEncoding() : this.reload()
                }
            }, {
                key: "componentDidUpdate",
                value: function() {
                    var e = this.props.attributes,
                        t = this.streamPlayer.current;
                    null !== t && null !== t.play && (t.autoPlay = e.autoplay, t.controls = e.controls, t.mute = e.mute, t.loop = e.loop, t.controls = e.controls, e.autoplay && "function" === typeof t.play ? t.play() : "function" === typeof t.pause && t.pause()), !1 !== e.uid && (jQuery("#block-" + this.instanceId + " .editor-media-placeholder__cancel-button").show(), this.reload())
                }
            }, {
                key: "toggleAttribute",
                value: function(e) {
                    var t = this.props.setAttributes;
                    return function(r) {
                        t(o({}, e, r))
                    }
                }
            }, {
                key: "open",
                value: function() {
                    var e = this;
                    this.mediaFrame.open(), this.mediaFrame.on("delete", function(t) {
                        e.delete(t)
                    }), this.mediaFrame.on("select", function() {
                        e.select()
                    })
                }
            }, {
                key: "select",
                value: function(e) {
                    (0, this.props.setAttributes)({
                        uid: e.uid,
                        thumbnail: e.thumb.src
                    }), this.setState({
                        editing: !1,
                        uploading: !1,
                        encoding: !1
                    }), cloudflareStream.analytics.logEvent("Stream WP Plugin - Added to blog post"), this.reload()
                }
            }, {
                key: "delete",
                value: function(e) {
                    jQuery.ajax({
                        url: ajaxurl + "?action=cloudflare-stream-delete",
                        data: {
                            nonce: cloudflareStream.nonce,
                            uid: e.uid
                        },
                        success: function() {
                            jQuery('li[data-id="' + e.id + '"]').hide()
                        },
                        error: function(e, t) {
                            console.error("Error: " + t), cloudflareStream.analytics.logEvent("Stream WP Plugin - Error")
                        }
                    })
                }
            }, {
                key: "update",
                value: function(e) {
                    jQuery(".settings-save-status .media-frame .spinner").css("visibility", "visible"), jQuery.ajax({
                        url: ajaxurl + "?action=cloudflare-stream-update",
                        method: "POST",
                        data: {
                            nonce: cloudflareStream.nonce,
                            uid: e.uid,
                            title: e.title
                        },
                        success: function() {
                            jQuery('li[data-id="' + e.id + '"]').hide()
                        },
                        error: function(e, t) {
                            console.error("Error: " + t), cloudflareStream.analytics.logEvent("Stream WP Plugin - Error")
                        }
                    })
                }
            }, {
                key: "reload",
                value: function() {
                    var e = this.props.attributes,
                        t = "https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=" + e.uid;
                    jQuery.getScript(t).fail(function(e, t, r) {
                        console.error("Exception:" + r)
                    })
                }
            }, {
                key: "uploadFromFiles",
                value: function(e) {
                    var t = this,
                        r = this.props.setAttributes,
                        o = jQuery("#progressbar-" + this.instanceId),
                        n = jQuery(".progress-label-" + this.instanceId),
                        i = o.progressbar("value") || 0;
                    o.progressbar("value", i);
                    var a = "https://api.cloudflare.com/client/v4/accounts/" + cloudflareStream.api.account + "/media";
                    cloudflareStream.analytics.logEvent("Stream WP Plugin - Started uploading a video");
                    var s = new l.a.Upload(e, {
                        resume: t.state.resume,
                        removeFingerprintOnSuccess: !0,
                        endpoint: a,
                        retryDelays: [0, 1e3, 3e3, 5e3],
                        headers: {
                            "X-Auth-Email": cloudflareStream.api.email,
                            "X-Auth-Key": cloudflareStream.api.key
                        },
                        chunkSize: 10485760,
                        metadata: {
                            name: e.name,
                            type: e.type
                        },
                        onError: function(e) {
                            console.error("Error: " + e), o.hide(), jQuery(".editor-media-placeholder .components-placeholder__instructions").html("Upload Error: See the console for details."), jQuery(".editor-media-placeholder__retry-button").show(), cloudflareStream.analytics.logEvent("Stream WP Plugin - Error")
                        },
                        onProgress: function(e, t) {
                            var r = parseInt(e / t * 100);
                            n.text(r + "%"), o.progressbar("option", "value", r)
                        },
                        onSuccess: function() {
                            var e = s.url.split("/"),
                                o = e[e.length - 1];
                                o = o.split("?")[0].split("#")[0];

                            r({
                                uid: o,
                                fingerprint: s.options.fingerprint(s.file, s.options)
                            }), cloudflareStream.analytics.logEvent("Stream WP Plugin - Finished uploading a video"), t.switchToEncoding()
                        }
                    });
                    s.start()
                }
            }, {
                key: "switchToEncoding",
                value: function() {
                    var e = this,
                        t = this;
                    t.setState({
                        editing: !0,
                        uploading: !1,
                        encoding: !0
                    }, function() {
                        var r = jQuery("#progressbar-" + e.instanceId),
                            o = jQuery(".progress-label-" + e.instanceId);
                        jQuery(".editor-media-placeholder .components-placeholder__instructions").html("Upload Complete. Processing video."), o.text(""), r.progressbar({
                            value: !1
                        }), t.encode()
                    })
                }
            }, {
                key: "encode",
                value: function() {
                    var e = this.props,
                        t = e.attributes,
                        r = e.setAttributes,
                        o = this,
                        n = jQuery("#progressbar-" + this.instanceId),
                        i = jQuery(".progress-label-" + this.instanceId),
                        a = this.props.attributes.file;
                    jQuery.ajax({
                        url: ajaxurl + "?action=cloudflare-stream-check-upload",
                        data: {
                            nonce: cloudflareStream.nonce,
                            uid: t.uid
                        },
                        success: function(e) {
                            if (e.success) {
                                if ("undefined" !== typeof e.data) {
                                    if (!0 === e.data.readyToStream && "undefined" !== typeof e.data.thumbnail ? (clearTimeout(o.encodingPoller), r({
                                            thumbnail: e.data.thumbnail
                                        }), o.setState({
                                            editing: !1,
                                            uploading: !1,
                                            encoding: !1
                                        })) : o.encodingPoller = setTimeout(function() {
                                            o.encode()
                                        }, 5e3), "queued" === e.data.status.state) i.text(""), n.progressbar({
                                        value: !1
                                    });
                                    else if ("inprogress" === e.data.status.state) {
                                        var t = Math.round(e.data.status.pctComplete);
                                        i.text(t + "%"), n.progressbar({
                                            value: t
                                        })
                                    }
                                    o.reload()
                                }
                            } else console.error("Error: " + e.data), !0 === o.state.resume ? (o.setState({
                                resume: !1
                            }), jQuery(".editor-media-placeholder .components-placeholder__instructions").html("Uploading your video."), o.uploadFromFiles(a)) : (n.hide(), jQuery(".editor-media-placeholder .components-placeholder__instructions").html("Processing Error: " + e.data), jQuery(".editor-media-placeholder__retry-button").show())
                        },
                        error: function(e, t) {
                            console.error("Error: " + t)
                        }
                    })
                }
            }, {
                key: "render",
                value: function() {
                    var e = this,
                        t = this.props.attributes,
                        r = t.uid,
                        o = t.autoplay,
                        n = t.controls,
                        i = t.loop,
                        a = t.muted,
                        s = this.props.className,
                        l = this.state,
                        u = l.editing,
                        d = l.uploading,
                        y = l.encoding,
                        v = function() {
                            e.setState({
                                editing: !0
                            }), e.setState({
                                uploading: !1
                            }), e.setState({
                                encoding: !1
                            })
                        },
                        C = function() {
                            e.setState({
                                editing: !1
                            }), e.setState({
                                uploading: !1
                            }), e.setState({
                                encoding: !1
                            })
                        },
                        j = function() {
                            var t = e.props.setAttributes;
                            jQuery(".editor-media-placeholder .components-placeholder__instructions").html("Processing your video");
                            var r = jQuery(".components-form-file-upload :input[type='file']")[0].files[0];
                            t({
                                file: r
                            });
                            var o = e;
                            o.setState({
                                editing: !0,
                                uploading: !0,
                                encoding: !1
                            }, function() {
                                jQuery("#progressbar-" + e.instanceId).progressbar({
                                    value: !1
                                }), o.uploadFromFiles(r)
                            })
                        };
                    if (u) {
                        if (d) {
                            var P = {
                                width: "100%"
                            };
                            return wp.element.createElement(_, {
                                icon: cloudflareStream.icon,
                                label: "Cloudflare Stream",
                                instructions: "Uploading your video.",
                                className: "editor-media-placeholder"
                            }, wp.element.createElement("div", {
                                id: "progressbar-" + this.instanceId,
                                style: P
                            }, wp.element.createElement("div", {
                                className: "progress-label progress-label-" + this.instanceId
                            }, "Connecting...")), wp.element.createElement(p, {
                                isDefault: !0,
                                isLarge: !0,
                                icon: "update",
                                label: c("Retry"),
                                onClick: v,
                                style: {
                                    display: "none"
                                },
                                className: "editor-media-placeholder__retry-button"
                            }, c("Retry")))
                        }
                        if (y) {
                            var k = {
                                width: "100%"
                            };
                            return wp.element.createElement(_, {
                                icon: cloudflareStream.icon,
                                label: "Cloudflare Stream",
                                instructions: "Processing your video.",
                                className: "editor-media-placeholder"
                            }, wp.element.createElement("div", {
                                id: "progressbar-" + this.instanceId,
                                style: k
                            }, wp.element.createElement("div", {
                                className: "progress-label progress-label-" + this.instanceId
                            }, "Connecting...")), wp.element.createElement(p, {
                                isDefault: !0,
                                isLarge: !0,
                                icon: "update",
                                label: c("Retry"),
                                onClick: v,
                                style: {
                                    display: "none"
                                },
                                className: "editor-media-placeholder__retry-button"
                            }, c("Retry")))
                        }
                        return cloudflareStream.api.key && "" !== cloudflareStream.api.key ? wp.element.createElement(_, {
                            icon: cloudflareStream.icon,
                            label: "Cloudflare Stream",
                            instructions: "Select a file from your library."
                        }, wp.element.createElement(g, {
                            isLarge: !0,
                            multiple: !0,
                            className: "editor-media-placeholder__upload-button",
                            onChange: j,
                            accept: "video/*"
                        }, c("Upload")), wp.element.createElement(S, {
                            type: "video",
                            className: s,
                            value: this.props.attributes,
                            render: function() {
                                return wp.element.createElement(p, {
                                    isLarge: !0,
                                    label: c("Stream Library"),
                                    onClick: e.open,
                                    className: "editor-media-placeholder__browse-button"
                                }, c("Stream Library"))
                            }
                        }), wp.element.createElement(p, {
                            isDefault: !0,
                            isLarge: !0,
                            icon: "cancel",
                            label: c("Cancel"),
                            onClick: C,
                            style: {
                                display: "none"
                            },
                            className: "editor-media-placeholder__cancel-button"
                        }, c("Cancel"))) : wp.element.createElement(_, {
                            icon: cloudflareStream.icon,
                            label: "Cloudflare Stream",
                            instructions: "Select a file from your library."
                        }, wp.element.createElement(S, {
                            type: "video",
                            className: s,
                            value: this.props.attributes,
                            render: function() {
                                return wp.element.createElement(p, {
                                    isLarge: !0,
                                    label: c("Stream Library"),
                                    onClick: e.open,
                                    className: "editor-media-placeholder__browse-button"
                                }, c("Stream Library"))
                            }
                        }), wp.element.createElement(p, {
                            isDefault: !0,
                            isLarge: !0,
                            icon: "cancel",
                            label: c("Cancel"),
                            onClick: C,
                            style: {
                                display: "none"
                            },
                            className: "editor-media-placeholder__cancel-button"
                        }, c("Cancel")))
                    }
                    return wp.element.createElement(A, null, wp.element.createElement(w, null, wp.element.createElement(m, null, wp.element.createElement(p, {
                        className: "components-icon-button components-toolbar__control",
                        label: c("Edit video"),
                        onClick: v,
                        icon: "edit"
                    }))), wp.element.createElement(E, null, wp.element.createElement(h, {
                        title: c("Video Settings")
                    }, wp.element.createElement(b, {
                        label: c("Autoplay"),
                        onChange: this.toggleAttribute("autoplay"),
                        checked: o
                    }), wp.element.createElement(b, {
                        label: c("Loop"),
                        onChange: this.toggleAttribute("loop"),
                        checked: i
                    }), wp.element.createElement(b, {
                        label: c("Muted"),
                        onChange: this.toggleAttribute("muted"),
                        checked: a
                    }), wp.element.createElement(b, {
                        label: c("Playback Controls"),
                        onChange: this.toggleAttribute("controls"),
                        checked: n
                    }))), wp.element.createElement("figure", {
                        className: s
                    }, wp.element.createElement(f, null, wp.element.createElement("stream", {
                        src: r,
                        controls: n,
                        autoPlay: o,
                        loop: i,
                        muted: a,
                        ref: this.streamPlayer
                    }))))
                }
            }]), t
        }(j);
    t.a = y(k)
}, function(e, t, r) {
    "use strict";
    var o = r(11),
        n = function(e) {
            return e && e.__esModule ? e : {
                default: e
            }
        }(o),
        i = r(1),
        a = n.default.defaultOptions,
        s = void 0;
    if ("undefined" !== typeof window) {
        var l = window,
            u = l.XMLHttpRequest,
            c = l.Blob;
        s = u && c && "function" === typeof c.prototype.slice
    } else s = !0;
    e.exports = {
        Upload: n.default,
        isSupported: s,
        canStoreURLs: i.canStoreURLs,
        defaultOptions: a
    }
}, function(e, t, r) {
    "use strict";

    function o(e) {
        return e && e.__esModule ? e : {
            default: e
        }
    }

    function n(e, t) {
        if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function")
    }

    function i(e) {
        var t = [];
        for (var r in e) t.push(r + " " + h.Base64.encode(e[r]));
        return t.join(",")
    }

    function a(e, t) {
        return e >= t && e < t + 100
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    });
    var s = function() {
            function e(e, t) {
                for (var r = 0; r < t.length; r++) {
                    var o = t[r];
                    o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, o.key, o)
                }
            }
            return function(t, r, o) {
                return r && e(t.prototype, r), o && e(t, o), t
            }
        }(),
        l = r(12),
        u = o(l),
        c = r(13),
        d = o(c),
        f = r(14),
        p = o(f),
        h = r(15),
        m = r(16),
        b = r(20),
        y = r(1),
        _ = function(e) {
            if (e && e.__esModule) return e;
            var t = {};
            if (null != e)
                for (var r in e) Object.prototype.hasOwnProperty.call(e, r) && (t[r] = e[r]);
            return t.default = e, t
        }(y),
        g = {
            endpoint: null,
            fingerprint: u.default,
            resume: !0,
            onProgress: null,
            onChunkComplete: null,
            onSuccess: null,
            onError: null,
            headers: {},
            chunkSize: 1 / 0,
            withCredentials: !1,
            uploadUrl: null,
            uploadSize: null,
            overridePatchMethod: !1,
            retryDelays: null,
            removeFingerprintOnSuccess: !1,
            uploadLengthDeferred: !1
        },
        v = function() {
            function e(t, r) {
                n(this, e), this.options = (0, p.default)(!0, {}, g, r), this.file = t, this.url = null, this._xhr = null, this._fingerprint = null, this._offset = null, this._aborted = !1, this._size = null, this._source = null, this._retryAttempt = 0, this._retryTimeout = null, this._offsetBeforeRetry = 0
            }
            return s(e, [{
                key: "start",
                value: function() {
                    var e = this,
                        t = this.file;
                    return t ? this.options.endpoint || this.options.uploadUrl ? void(this._source ? this._start(this._source) : (0, b.getSource)(t, this.options.chunkSize, function(t, r) {
                        if (t) return void e._emitError(t);
                        e._source = r, e._start(r)
                    })) : void this._emitError(new Error("tus: neither an endpoint or an upload URL is provided")) : void this._emitError(new Error("tus: no file or stream to upload provided"))
                }
            }, {
                key: "_start",
                value: function(e) {
                    var t = this,
                        r = this.file;
                    if (this.options.uploadLengthDeferred) this._size = null;
                    else if (null != this.options.uploadSize) {
                        if (this._size = +this.options.uploadSize, isNaN(this._size)) return void this._emitError(new Error("tus: cannot convert `uploadSize` option into a number"))
                    } else if (this._size = e.size, null == this._size) return void this._emitError(new Error("tus: cannot automatically derive upload's size from input and must be specified manually using the `uploadSize` option"));
                    var o = this.options.retryDelays;
                    if (null != o) {
                        if ("[object Array]" !== Object.prototype.toString.call(o)) return void this._emitError(new Error("tus: the `retryDelays` option must either be an array or null"));
                        var n = this.options.onError;
                        this.options.onError = function(e) {
                            t.options.onError = n, null != t._offset && t._offset > t._offsetBeforeRetry && (t._retryAttempt = 0);
                            var r = !0;
                            "undefined" !== typeof window && "navigator" in window && !1 === window.navigator.onLine && (r = !1);
                            var i = e.originalRequest ? e.originalRequest.status : 0,
                                s = !a(i, 400) || 409 === i || 423 === i;
                            if (!(t._retryAttempt < o.length && null != e.originalRequest && s && r)) return void t._emitError(e);
                            var l = o[t._retryAttempt++];
                            t._offsetBeforeRetry = t._offset, t.options.uploadUrl = t.url, t._retryTimeout = setTimeout(function() {
                                t.start()
                            }, l)
                        }
                    }
                    if (this._aborted = !1, null != this.url) return void this._resumeUpload();
                    if (null != this.options.uploadUrl) return this.url = this.options.uploadUrl, void this._resumeUpload();
                    if (this.options.resume) {
                        this._fingerprint = this.options.fingerprint(r, this.options);
                        var i = _.getItem(this._fingerprint);
                        if (null != i) return this.url = i, void this._resumeUpload()
                    }
                    this._createUpload()
                }
            }, {
                key: "abort",
                value: function() {
                    null !== this._xhr && (this._xhr.abort(), this._source.close(), this._aborted = !0), null != this._retryTimeout && (clearTimeout(this._retryTimeout), this._retryTimeout = null)
                }
            }, {
                key: "_emitXhrError",
                value: function(e, t, r) {
                    this._emitError(new d.default(t, r, e))
                }
            }, {
                key: "_emitError",
                value: function(e) {
                    if ("function" !== typeof this.options.onError) throw e;
                    this.options.onError(e)
                }
            }, {
                key: "_emitSuccess",
                value: function() {
                    "function" === typeof this.options.onSuccess && this.options.onSuccess()
                }
            }, {
                key: "_emitProgress",
                value: function(e, t) {
                    "function" === typeof this.options.onProgress && this.options.onProgress(e, t)
                }
            }, {
                key: "_emitChunkComplete",
                value: function(e, t, r) {
                    "function" === typeof this.options.onChunkComplete && this.options.onChunkComplete(e, t, r)
                }
            }, {
                key: "_setupXHR",
                value: function(e) {
                    this._xhr = e, e.setRequestHeader("Tus-Resumable", "1.0.0");
                    var t = this.options.headers;
                    for (var r in t) e.setRequestHeader(r, t[r]);
                    e.withCredentials = this.options.withCredentials
                }
            }, {
                key: "_createUpload",
                value: function() {
                    var e = this;
                    if (!this.options.endpoint) return void this._emitError(new Error("tus: unable to create upload because no endpoint is provided"));
                    var t = (0, m.newRequest)();
                    t.open("POST", this.options.endpoint, !0), t.onload = function() {
                        if (!a(t.status, 200)) return void e._emitXhrError(t, new Error("tus: unexpected response while creating upload"));
                        var r = t.getResponseHeader("Location");
                        return null == r ? void e._emitXhrError(t, new Error("tus: invalid or missing Location header")) : (e.url = (0, m.resolveUrl)(e.options.endpoint, r), 0 === e._size ? (e._emitSuccess(), void e._source.close()) : (e.options.resume && _.setItem(e._fingerprint, e.url), e._offset = 0, void e._startUpload()))
                    }, t.onerror = function(r) {
                        e._emitXhrError(t, new Error("tus: failed to create upload"), r)
                    }, this._setupXHR(t), this.options.uploadLengthDeferred ? t.setRequestHeader("Upload-Defer-Length", 1) : t.setRequestHeader("Upload-Length", this._size);
                    var r = i(this.options.metadata);
                    "" !== r && t.setRequestHeader("Upload-Metadata", r), t.send(null)
                }
            }, {
                key: "_resumeUpload",
                value: function() {
                    var e = this,
                        t = (0, m.newRequest)();
                    t.open("HEAD", this.url, !0), t.onload = function() {
                        if (!a(t.status, 200)) return e.options.resume && a(t.status, 400) && _.removeItem(e._fingerprint), 423 === t.status ? void e._emitXhrError(t, new Error("tus: upload is currently locked; retry later")) : e.options.endpoint ? (e.url = null, void e._createUpload()) : void e._emitXhrError(t, new Error("tus: unable to resume upload (new upload cannot be created without an endpoint)"));
                        var r = parseInt(t.getResponseHeader("Upload-Offset"), 10);
                        if (isNaN(r)) return void e._emitXhrError(t, new Error("tus: invalid or missing offset value"));
                        var o = parseInt(t.getResponseHeader("Upload-Length"), 10);
                        return isNaN(o) && !e.options.uploadLengthDeferred ? void e._emitXhrError(t, new Error("tus: invalid or missing length value")) : r === o ? (e._emitProgress(o, o), void e._emitSuccess()) : (e._offset = r, void e._startUpload())
                    }, t.onerror = function(r) {
                        e._emitXhrError(t, new Error("tus: failed to resume upload"), r)
                    }, this._setupXHR(t), t.send(null)
                }
            }, {
                key: "_startUpload",
                value: function() {
                    var e = this;
                    if (!this._aborted) {
                        var t = (0, m.newRequest)();
                        this.options.overridePatchMethod ? (t.open("POST", this.url, !0), t.setRequestHeader("X-HTTP-Method-Override", "PATCH")) : t.open("PATCH", this.url, !0), t.onload = function() {
                            if (!a(t.status, 200)) return void e._emitXhrError(t, new Error("tus: unexpected response while uploading chunk"));
                            var r = parseInt(t.getResponseHeader("Upload-Offset"), 10);
                            return isNaN(r) ? void e._emitXhrError(t, new Error("tus: invalid or missing offset value")) : (e._emitProgress(r, e._size), e._emitChunkComplete(r - e._offset, r, e._size), e._offset = r, r == e._size ? (e.options.removeFingerprintOnSuccess && e.options.resume && _.removeItem(e._fingerprint), e._emitSuccess(), void e._source.close()) : void e._startUpload())
                        }, t.onerror = function(r) {
                            e._aborted || e._emitXhrError(t, new Error("tus: failed to upload chunk at offset " + e._offset), r)
                        }, "upload" in t && (t.upload.onprogress = function(t) {
                            t.lengthComputable && e._emitProgress(r + t.loaded, e._size)
                        }), this._setupXHR(t), t.setRequestHeader("Upload-Offset", this._offset), t.setRequestHeader("Content-Type", "application/offset+octet-stream");
                        var r = this._offset,
                            o = this._offset + this.options.chunkSize;
                        (o === 1 / 0 || o > this._size) && !this.options.uploadLengthDeferred && (o = this._size), this._source.slice(r, o, function(r, o, n) {
                            if (r) return void e._emitError(r);
                            e.options.uploadLengthDeferred && n && (e._size = e._offset + (o && o.size ? o.size : 0), t.setRequestHeader("Upload-Length", e._size)), null === o ? t.send() : (t.send(o), e._emitProgress(e._offset, e._size))
                        })
                    }
                }
            }]), e
        }();
    v.defaultOptions = g, t.default = v
}, function(e, t, r) {
    "use strict";

    function o(e, t) {
        return ["tus", e.name, e.type, e.size, e.lastModified, t.endpoint].join("-")
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    }), t.default = o
}, function(e, t, r) {
    "use strict";

    function o(e, t) {
        if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function")
    }

    function n(e, t) {
        if (!e) throw new ReferenceError("this hasn't been initialised - super() hasn't been called");
        return !t || "object" !== typeof t && "function" !== typeof t ? e : t
    }

    function i(e, t) {
        if ("function" !== typeof t && null !== t) throw new TypeError("Super expression must either be null or a function, not " + typeof t);
        e.prototype = Object.create(t && t.prototype, {
            constructor: {
                value: e,
                enumerable: !1,
                writable: !0,
                configurable: !0
            }
        }), t && (Object.setPrototypeOf ? Object.setPrototypeOf(e, t) : e.__proto__ = t)
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    });
    var a = function(e) {
        function t(e) {
            var r = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : null,
                i = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : null;
            o(this, t);
            var a = n(this, (t.__proto__ || Object.getPrototypeOf(t)).call(this, e.message));
            a.originalRequest = i, a.causingError = r;
            var s = e.message;
            return null != r && (s += ", caused by " + r.toString()), null != i && (s += ", originated from request (response code: " + i.status + ", response text: " + i.responseText + ")"), a.message = s, a
        }
        return i(t, e), t
    }(Error);
    t.default = a
}, function(e, t, r) {
    "use strict";
    var o = Object.prototype.hasOwnProperty,
        n = Object.prototype.toString,
        i = Object.defineProperty,
        a = Object.getOwnPropertyDescriptor,
        s = function(e) {
            return "function" === typeof Array.isArray ? Array.isArray(e) : "[object Array]" === n.call(e)
        },
        l = function(e) {
            if (!e || "[object Object]" !== n.call(e)) return !1;
            var t = o.call(e, "constructor"),
                r = e.constructor && e.constructor.prototype && o.call(e.constructor.prototype, "isPrototypeOf");
            if (e.constructor && !t && !r) return !1;
            var i;
            for (i in e);
            return "undefined" === typeof i || o.call(e, i)
        },
        u = function(e, t) {
            i && "__proto__" === t.name ? i(e, t.name, {
                enumerable: !0,
                configurable: !0,
                value: t.newValue,
                writable: !0
            }) : e[t.name] = t.newValue
        },
        c = function(e, t) {
            if ("__proto__" === t) {
                if (!o.call(e, t)) return;
                if (a) return a(e, t).value
            }
            return e[t]
        };
    e.exports = function e() {
        var t, r, o, n, i, a, d = arguments[0],
            f = 1,
            p = arguments.length,
            h = !1;
        for ("boolean" === typeof d && (h = d, d = arguments[1] || {}, f = 2), (null == d || "object" !== typeof d && "function" !== typeof d) && (d = {}); f < p; ++f)
            if (null != (t = arguments[f]))
                for (r in t) o = c(d, r), n = c(t, r), d !== n && (h && n && (l(n) || (i = s(n))) ? (i ? (i = !1, a = o && s(o) ? o : []) : a = o && l(o) ? o : {}, u(d, {
                    name: r,
                    newValue: e(h, a, n)
                })) : "undefined" !== typeof n && u(d, {
                    name: r,
                    newValue: n
                }));
        return d
    }
}, function(module, exports, __webpack_require__) {
    (function(global) {
        var __WEBPACK_AMD_DEFINE_ARRAY__, __WEBPACK_AMD_DEFINE_RESULT__;
        ! function(e, t) {
            module.exports = t(e)
        }("undefined" !== typeof self ? self : "undefined" !== typeof window ? window : "undefined" !== typeof global ? global : this, function(global) {
            "use strict";
            global = global || {};
            var _Base64 = global.Base64,
                version = "2.5.1",
                buffer;
            if ("undefined" !== typeof module && module.exports) try {
                buffer = eval("require('buffer').Buffer")
            } catch (e) {
                buffer = void 0
            }
            var b64chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",
                b64tab = function(e) {
                    for (var t = {}, r = 0, o = e.length; r < o; r++) t[e.charAt(r)] = r;
                    return t
                }(b64chars),
                fromCharCode = String.fromCharCode,
                cb_utob = function(e) {
                    if (e.length < 2) {
                        var t = e.charCodeAt(0);
                        return t < 128 ? e : t < 2048 ? fromCharCode(192 | t >>> 6) + fromCharCode(128 | 63 & t) : fromCharCode(224 | t >>> 12 & 15) + fromCharCode(128 | t >>> 6 & 63) + fromCharCode(128 | 63 & t)
                    }
                    var t = 65536 + 1024 * (e.charCodeAt(0) - 55296) + (e.charCodeAt(1) - 56320);
                    return fromCharCode(240 | t >>> 18 & 7) + fromCharCode(128 | t >>> 12 & 63) + fromCharCode(128 | t >>> 6 & 63) + fromCharCode(128 | 63 & t)
                },
                re_utob = /[\uD800-\uDBFF][\uDC00-\uDFFFF]|[^\x00-\x7F]/g,
                utob = function(e) {
                    return e.replace(re_utob, cb_utob)
                },
                cb_encode = function(e) {
                    var t = [0, 2, 1][e.length % 3],
                        r = e.charCodeAt(0) << 16 | (e.length > 1 ? e.charCodeAt(1) : 0) << 8 | (e.length > 2 ? e.charCodeAt(2) : 0);
                    return [b64chars.charAt(r >>> 18), b64chars.charAt(r >>> 12 & 63), t >= 2 ? "=" : b64chars.charAt(r >>> 6 & 63), t >= 1 ? "=" : b64chars.charAt(63 & r)].join("")
                },
                btoa = global.btoa ? function(e) {
                    return global.btoa(e)
                } : function(e) {
                    return e.replace(/[\s\S]{1,3}/g, cb_encode)
                },
                _encode = buffer ? buffer.from && Uint8Array && buffer.from !== Uint8Array.from ? function(e) {
                    return (e.constructor === buffer.constructor ? e : buffer.from(e)).toString("base64")
                } : function(e) {
                    return (e.constructor === buffer.constructor ? e : new buffer(e)).toString("base64")
                } : function(e) {
                    return btoa(utob(e))
                },
                encode = function(e, t) {
                    return t ? _encode(String(e)).replace(/[+\/]/g, function(e) {
                        return "+" == e ? "-" : "_"
                    }).replace(/=/g, "") : _encode(String(e))
                },
                encodeURI = function(e) {
                    return encode(e, !0)
                },
                re_btou = new RegExp(["[\xc0-\xdf][\x80-\xbf]", "[\xe0-\xef][\x80-\xbf]{2}", "[\xf0-\xf7][\x80-\xbf]{3}"].join("|"), "g"),
                cb_btou = function(e) {
                    switch (e.length) {
                        case 4:
                            var t = (7 & e.charCodeAt(0)) << 18 | (63 & e.charCodeAt(1)) << 12 | (63 & e.charCodeAt(2)) << 6 | 63 & e.charCodeAt(3),
                                r = t - 65536;
                            return fromCharCode(55296 + (r >>> 10)) + fromCharCode(56320 + (1023 & r));
                        case 3:
                            return fromCharCode((15 & e.charCodeAt(0)) << 12 | (63 & e.charCodeAt(1)) << 6 | 63 & e.charCodeAt(2));
                        default:
                            return fromCharCode((31 & e.charCodeAt(0)) << 6 | 63 & e.charCodeAt(1))
                    }
                },
                btou = function(e) {
                    return e.replace(re_btou, cb_btou)
                },
                cb_decode = function(e) {
                    var t = e.length,
                        r = t % 4,
                        o = (t > 0 ? b64tab[e.charAt(0)] << 18 : 0) | (t > 1 ? b64tab[e.charAt(1)] << 12 : 0) | (t > 2 ? b64tab[e.charAt(2)] << 6 : 0) | (t > 3 ? b64tab[e.charAt(3)] : 0),
                        n = [fromCharCode(o >>> 16), fromCharCode(o >>> 8 & 255), fromCharCode(255 & o)];
                    return n.length -= [0, 0, 2, 1][r], n.join("")
                },
                _atob = global.atob ? function(e) {
                    return global.atob(e)
                } : function(e) {
                    return e.replace(/\S{1,4}/g, cb_decode)
                },
                atob = function(e) {
                    return _atob(String(e).replace(/[^A-Za-z0-9\+\/]/g, ""))
                },
                _decode = buffer ? buffer.from && Uint8Array && buffer.from !== Uint8Array.from ? function(e) {
                    return (e.constructor === buffer.constructor ? e : buffer.from(e, "base64")).toString()
                } : function(e) {
                    return (e.constructor === buffer.constructor ? e : new buffer(e, "base64")).toString()
                } : function(e) {
                    return btou(_atob(e))
                },
                decode = function(e) {
                    return _decode(String(e).replace(/[-_]/g, function(e) {
                        return "-" == e ? "+" : "/"
                    }).replace(/[^A-Za-z0-9\+\/]/g, ""))
                },
                noConflict = function() {
                    var e = global.Base64;
                    return global.Base64 = _Base64, e
                };
            if (global.Base64 = {
                    VERSION: version,
                    atob: atob,
                    btoa: btoa,
                    fromBase64: decode,
                    toBase64: encode,
                    utob: utob,
                    encode: encode,
                    encodeURI: encodeURI,
                    btou: btou,
                    decode: decode,
                    noConflict: noConflict,
                    __buffer__: buffer
                }, "function" === typeof Object.defineProperty) {
                var noEnum = function(e) {
                    return {
                        value: e,
                        enumerable: !1,
                        writable: !0,
                        configurable: !0
                    }
                };
                global.Base64.extendString = function() {
                    Object.defineProperty(String.prototype, "fromBase64", noEnum(function() {
                        return decode(this)
                    })), Object.defineProperty(String.prototype, "toBase64", noEnum(function(e) {
                        return encode(this, e)
                    })), Object.defineProperty(String.prototype, "toBase64URI", noEnum(function() {
                        return encode(this, !0)
                    }))
                }
            }
            return global.Meteor && (Base64 = global.Base64), "undefined" !== typeof module && module.exports ? module.exports.Base64 = global.Base64 : (__WEBPACK_AMD_DEFINE_ARRAY__ = [], void 0 !== (__WEBPACK_AMD_DEFINE_RESULT__ = function() {
                return global.Base64
            }.apply(exports, __WEBPACK_AMD_DEFINE_ARRAY__)) && (module.exports = __WEBPACK_AMD_DEFINE_RESULT__)), {
                Base64: global.Base64
            }
        })
    }).call(exports, __webpack_require__(0))
}, function(e, t, r) {
    "use strict";

    function o() {
        return new window.XMLHttpRequest
    }

    function n(e, t) {
        return new a.default(t, e).toString()
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    }), t.newRequest = o, t.resolveUrl = n;
    var i = r(17),
        a = function(e) {
            return e && e.__esModule ? e : {
                default: e
            }
        }(i)
}, function(e, t, r) {
    "use strict";
    (function(t) {
        function o(e) {
            return (e || "").toString().replace(h, "")
        }

        function n(e) {
            var r;
            r = "undefined" !== typeof window ? window : "undefined" !== typeof t ? t : "undefined" !== typeof self ? self : {};
            var o = r.location || {};
            e = e || o;
            var n, i = {},
                a = typeof e;
            if ("blob:" === e.protocol) i = new s(unescape(e.pathname), {});
            else if ("string" === a) {
                i = new s(e, {});
                for (n in b) delete i[n]
            } else if ("object" === a) {
                for (n in e) n in b || (i[n] = e[n]);
                void 0 === i.slashes && (i.slashes = f.test(e.href))
            }
            return i
        }

        function i(e) {
            e = o(e);
            var t = p.exec(e);
            return {
                protocol: t[1] ? t[1].toLowerCase() : "",
                slashes: !!t[2],
                rest: t[3]
            }
        }

        function a(e, t) {
            if ("" === e) return t;
            for (var r = (t || "/").split("/").slice(0, -1).concat(e.split("/")), o = r.length, n = r[o - 1], i = !1, a = 0; o--;) "." === r[o] ? r.splice(o, 1) : ".." === r[o] ? (r.splice(o, 1), a++) : a && (0 === o && (i = !0), r.splice(o, 1), a--);
            return i && r.unshift(""), "." !== n && ".." !== n || r.push(""), r.join("/")
        }

        function s(e, t, r) {
            if (e = o(e), !(this instanceof s)) return new s(e, t, r);
            var l, u, f, p, h, b, y = m.slice(),
                _ = typeof t,
                g = this,
                v = 0;
            for ("object" !== _ && "string" !== _ && (r = t, t = null), r && "function" !== typeof r && (r = d.parse), t = n(t), u = i(e || ""), l = !u.protocol && !u.slashes, g.slashes = u.slashes || l && t.slashes, g.protocol = u.protocol || t.protocol || "", e = u.rest, u.slashes || (y[3] = [/(.*)/, "pathname"]); v < y.length; v++) p = y[v], "function" !== typeof p ? (f = p[0], b = p[1], f !== f ? g[b] = e : "string" === typeof f ? ~(h = e.indexOf(f)) && ("number" === typeof p[2] ? (g[b] = e.slice(0, h), e = e.slice(h + p[2])) : (g[b] = e.slice(h), e = e.slice(0, h))) : (h = f.exec(e)) && (g[b] = h[1], e = e.slice(0, h.index)), g[b] = g[b] || (l && p[3] ? t[b] || "" : ""), p[4] && (g[b] = g[b].toLowerCase())) : e = p(e);
            r && (g.query = r(g.query)), l && t.slashes && "/" !== g.pathname.charAt(0) && ("" !== g.pathname || "" !== t.pathname) && (g.pathname = a(g.pathname, t.pathname)), c(g.port, g.protocol) || (g.host = g.hostname, g.port = ""), g.username = g.password = "", g.auth && (p = g.auth.split(":"), g.username = p[0] || "", g.password = p[1] || ""), g.origin = g.protocol && g.host && "file:" !== g.protocol ? g.protocol + "//" + g.host : "null", g.href = g.toString()
        }

        function l(e, t, r) {
            var o = this;
            switch (e) {
                case "query":
                    "string" === typeof t && t.length && (t = (r || d.parse)(t)), o[e] = t;
                    break;
                case "port":
                    o[e] = t, c(t, o.protocol) ? t && (o.host = o.hostname + ":" + t) : (o.host = o.hostname, o[e] = "");
                    break;
                case "hostname":
                    o[e] = t, o.port && (t += ":" + o.port), o.host = t;
                    break;
                case "host":
                    o[e] = t, /:\d+$/.test(t) ? (t = t.split(":"), o.port = t.pop(), o.hostname = t.join(":")) : (o.hostname = t, o.port = "");
                    break;
                case "protocol":
                    o.protocol = t.toLowerCase(), o.slashes = !r;
                    break;
                case "pathname":
                case "hash":
                    if (t) {
                        var n = "pathname" === e ? "/" : "#";
                        o[e] = t.charAt(0) !== n ? n + t : t
                    } else o[e] = t;
                    break;
                default:
                    o[e] = t
            }
            for (var i = 0; i < m.length; i++) {
                var a = m[i];
                a[4] && (o[a[1]] = o[a[1]].toLowerCase())
            }
            return o.origin = o.protocol && o.host && "file:" !== o.protocol ? o.protocol + "//" + o.host : "null", o.href = o.toString(), o
        }

        function u(e) {
            e && "function" === typeof e || (e = d.stringify);
            var t, r = this,
                o = r.protocol;
            o && ":" !== o.charAt(o.length - 1) && (o += ":");
            var n = o + (r.slashes ? "//" : "");
            return r.username && (n += r.username, r.password && (n += ":" + r.password), n += "@"), n += r.host + r.pathname, t = "object" === typeof r.query ? e(r.query) : r.query, t && (n += "?" !== t.charAt(0) ? "?" + t : t), r.hash && (n += r.hash), n
        }
        var c = r(18),
            d = r(19),
            f = /^[A-Za-z][A-Za-z0-9+-.]*:\/\//,
            p = /^([a-z][a-z0-9.+-]*:)?(\/\/)?([\S\s]*)/i,
            h = new RegExp("^[\\x09\\x0A\\x0B\\x0C\\x0D\\x20\\xA0\\u1680\\u180E\\u2000\\u2001\\u2002\\u2003\\u2004\\u2005\\u2006\\u2007\\u2008\\u2009\\u200A\\u202F\\u205F\\u3000\\u2028\\u2029\\uFEFF]+"),
            m = [
                ["#", "hash"],
                ["?", "query"],
                function(e) {
                    return e.replace("\\", "/")
                },
                ["/", "pathname"],
                ["@", "auth", 1],
                [NaN, "host", void 0, 1, 1],
                [/:(\d+)$/, "port", void 0, 1],
                [NaN, "hostname", void 0, 1, 1]
            ],
            b = {
                hash: 1,
                query: 1
            };
        s.prototype = {
            set: l,
            toString: u
        }, s.extractProtocol = i, s.location = n, s.trimLeft = o, s.qs = d, e.exports = s
    }).call(t, r(0))
}, function(e, t, r) {
    "use strict";
    e.exports = function(e, t) {
        if (t = t.split(":")[0], !(e = +e)) return !1;
        switch (t) {
            case "http":
            case "ws":
                return 80 !== e;
            case "https":
            case "wss":
                return 443 !== e;
            case "ftp":
                return 21 !== e;
            case "gopher":
                return 70 !== e;
            case "file":
                return !1
        }
        return 0 !== e
    }
}, function(e, t, r) {
    "use strict";

    function o(e) {
        try {
            return decodeURIComponent(e.replace(/\+/g, " "))
        } catch (e) {
            return null
        }
    }

    function n(e) {
        for (var t, r = /([^=?&]+)=?([^&]*)/g, n = {}; t = r.exec(e);) {
            var i = o(t[1]),
                a = o(t[2]);
            null === i || null === a || i in n || (n[i] = a)
        }
        return n
    }

    function i(e, t) {
        t = t || "";
        var r, o, n = [];
        "string" !== typeof t && (t = "?");
        for (o in e)
            if (s.call(e, o)) {
                if (r = e[o], r || null !== r && r !== a && !isNaN(r) || (r = ""), o = encodeURIComponent(o), r = encodeURIComponent(r), null === o || null === r) continue;
                n.push(o + "=" + r)
            }
        return n.length ? t + n.join("&") : ""
    }
    var a, s = Object.prototype.hasOwnProperty;
    t.stringify = i, t.parse = n
}, function(e, t, r) {
    "use strict";

    function o(e) {
        return e && e.__esModule ? e : {
            default: e
        }
    }

    function n(e, t) {
        if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function")
    }

    function i(e) {
        return void 0 === e ? 0 : void 0 !== e.size ? e.size : e.length
    }

    function a(e, t) {
        if (e.concat) return e.concat(t);
        if (e instanceof Blob) return new Blob([e, t], {
            type: e.type
        });
        if (e.set) {
            var r = new e.constructor(e.length + t.length);
            return r.set(e), r.set(t, e.length), r
        }
        throw new Error("Unknown data type")
    }

    function s(e, t, r) {
        return (c.default || window.__tus__forceReactNative) && e && "undefined" !== typeof e.uri ? void(0, f.default)(e.uri, function(e, t) {
            if (e) return r(new Error("tus: cannot fetch `file.uri` as Blob, make sure the uri is correct and accessible. " + e));
            r(null, new y(t))
        }) : "function" === typeof e.slice && "undefined" !== typeof e.size ? void r(null, new y(e)) : "function" === typeof e.read ? (t = +t, isFinite(t) ? void r(null, new _(e, t)) : void r(new Error("cannot create source for stream without a finite value for the `chunkSize` option"))) : void r(new Error("source object may only be an instance of File, Blob, or Reader in this environment"))
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    });
    var l = function() {
        function e(e, t) {
            for (var r = 0; r < t.length; r++) {
                var o = t[r];
                o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, o.key, o)
            }
        }
        return function(t, r, o) {
            return r && e(t.prototype, r), o && e(t, o), t
        }
    }();
    t.getSource = s;
    var u = r(21),
        c = o(u),
        d = r(22),
        f = o(d),
        p = r(23),
        h = o(p),
        m = r(24),
        b = o(m),
        y = function() {
            function e(t) {
                n(this, e), this._file = t, this.size = t.size
            }
            return l(e, [{
                key: "slice",
                value: function(e, t, r) {
                    if ((0, h.default)()) return void(0, b.default)(this._file.slice(e, t), function(e, t) {
                        if (e) return r(e);
                        r(null, t)
                    });
                    r(null, this._file.slice(e, t))
                }
            }, {
                key: "close",
                value: function() {}
            }]), e
        }(),
        _ = function() {
            function e(t, r) {
                n(this, e), this._chunkSize = r, this._buffer = void 0, this._bufferOffset = 0, this._reader = t, this._done = !1
            }
            return l(e, [{
                key: "slice",
                value: function(e, t, r) {
                    return e < this._bufferOffset ? void r(new Error("Requested data is before the reader's current offset")) : this._readUntilEnoughDataOrDone(e, t, r)
                }
            }, {
                key: "_readUntilEnoughDataOrDone",
                value: function(e, t, r) {
                    var o = this,
                        n = t <= this._bufferOffset + i(this._buffer);
                    if (this._done || n) {
                        var s = this._getDataFromBuffer(e, t);
                        return void r(null, s, null == s && this._done)
                    }
                    this._reader.read().then(function(n) {
                        var i = n.value;
                        n.done ? o._done = !0 : void 0 === o._buffer ? o._buffer = i : o._buffer = a(o._buffer, i), o._readUntilEnoughDataOrDone(e, t, r)
                    }).catch(function(e) {
                        r(new Error("Error during read: " + e))
                    })
                }
            }, {
                key: "_getDataFromBuffer",
                value: function(e, t) {
                    e > this._bufferOffset && (this._buffer = this._buffer.slice(e - this._bufferOffset), this._bufferOffset = e);
                    var r = 0 === i(this._buffer);
                    return this._done && r ? null : this._buffer.slice(0, t - e)
                }
            }, {
                key: "close",
                value: function() {
                    this._reader.cancel && this._reader.cancel()
                }
            }]), e
        }()
}, function(e, t, r) {
    "use strict";
    Object.defineProperty(t, "__esModule", {
        value: !0
    });
    var o = "undefined" !== typeof navigator && "string" === typeof navigator.product && "reactnative" === navigator.product.toLowerCase();
    t.default = o
}, function(e, t, r) {
    "use strict";

    function o(e, t) {
        var r = new XMLHttpRequest;
        r.responseType = "blob", r.onload = function() {
            var e = r.response;
            t(null, e)
        }, r.onerror = function(e) {
            t(e)
        }, r.open("GET", e), r.send()
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    }), t.default = o
}, function(e, t, r) {
    "use strict";
    Object.defineProperty(t, "__esModule", {
        value: !0
    });
    var o = function() {
        return "undefined" != typeof window && ("undefined" != typeof window.PhoneGap || "undefined" != typeof window.Cordova || "undefined" != typeof window.cordova)
    };
    t.default = o
}, function(e, t, r) {
    "use strict";

    function o(e, t) {
        var r = new FileReader;
        r.onload = function() {
            t(null, new Uint8Array(r.result))
        }, r.onerror = function(e) {
            t(e)
        }, r.readAsArrayBuffer(e)
    }
    Object.defineProperty(t, "__esModule", {
        value: !0
    }), t.default = o
}, function(e, t) {
    function r(e, t) {
        if (!(e instanceof t)) throw new TypeError("Cannot call a class as a function")
    }
    var o = function() {
            function e(e, t) {
                for (var r = 0; r < t.length; r++) {
                    var o = t[r];
                    o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, o.key, o)
                }
            }
            return function(t, r, o) {
                return r && e(t.prototype, r), o && e(t, o), t
            }
        }(),
        n = function() {
            function e() {
                r(this, e), jQuery("#submit").on("click", function() {
                    cloudflareStream.analytics.logEvent("Stream WP Plugin - Settings Saved")
                })
            }
            return o(e, [{
                key: "logEvent",
                value: function(e) {
                    cloudflareStream.options.heap || (console.error("Event: " + e), jQuery.ajax({
                        url: ajaxurl + "?action=cloudflare-stream-analytics",
                        method: "POST",
                        data: {
                            nonce: cloudflareStream.nonce,
                            event: e
                        },
                        error: function(e, t) {
                            console.error("Error: " + t)
                        }
                    }))
                }
            }]), e
        }();
    cloudflareStream.analytics = new n
}]);