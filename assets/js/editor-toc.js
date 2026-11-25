(function (wp) {
  'use strict';

  if (
    !wp ||
    !wp.plugins ||
    !wp.components ||
    !wp.data ||
    !wp.element ||
    !window.penalisEditorToc
  ) {
    return;
  }

  var registerPlugin = wp.plugins.registerPlugin;
  var PluginPostStatusInfo =
    (wp.editor && wp.editor.PluginPostStatusInfo) ||
    (wp.editPost && wp.editPost.PluginPostStatusInfo);

  if (!PluginPostStatusInfo) {
    return;
  }

  var el = wp.element.createElement;
  var CheckboxControl = wp.components.CheckboxControl;
  var useSelect = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var config = window.penalisEditorToc;
  var metaKey = config.metaKey || '_penalis_toc_disabled';

  function PenalisTocPostStatusRow() {
    var postMeta = useSelect(function (select) {
      var editor = select('core/editor');
      if (!editor) {
        return {};
      }
      return editor.getEditedPostAttribute('meta') || {};
    }, []);

    var editPost = useDispatch('core/editor').editPost;

    var rawValue = postMeta[metaKey];
    var isDisabled;
    if (rawValue === '1' || rawValue === 1 || rawValue === true) {
      isDisabled = true;
    } else if (rawValue === '0' || rawValue === 0 || rawValue === false) {
      isDisabled = false;
    } else {
      isDisabled = Boolean(config.legacyDisabled);
    }

    return el(
      PluginPostStatusInfo,
      { className: 'penalis-editor-toc-row' },
      el(CheckboxControl, {
        label: 'Nonaktifkan Table of Contents',
        help: config.globallyEnabled
          ? 'Sembunyikan daftar isi otomatis pada artikel ini.'
          : 'Table of Contents otomatis sedang dinonaktifkan di Pengaturan Penalis.',
        checked: isDisabled,
        onChange: function (checked) {
          var nextMeta = {};
          nextMeta[metaKey] = checked ? '1' : '0';
          editPost({ meta: nextMeta });
        },
        __nextHasNoMarginBottom: true,
      })
    );
  }

  registerPlugin('penalis-editor-toc', {
    render: PenalisTocPostStatusRow,
  });
})(window.wp);
