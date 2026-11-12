import { ToolbarButton, showErrorMessage } from '@jupyterlab/apputils';
import { PageConfig } from '@jupyterlab/coreutils';

export default {
  id: '@moodle/notebook:submit', autoStart: true,
  activate(app) {
    const target = PageConfig.getOption('moodleReturnUrl');
    if (!target || new URL(target).protocol !== 'https:') return;
    const teacher = PageConfig.getOption('moodleTeacher') === '1';
    app.commands.addCommand('moodle:submit', {
      label: teacher ? 'Enregistrer une version dans Moodle' : 'Enregistrer et remettre dans Moodle',
      execute: async () => {
        const tab = window.open('about:blank', '_blank');
        if (tab) tab.opener = null;
        try {
          for (const widget of app.shell.widgets('main')) {
            if (widget.context?.model?.dirty) await widget.context.save();
          }
          if (tab) tab.location.href = target;
          else window.location.href = target;
        } catch (error) {
          tab?.close();
          await showErrorMessage('Enregistrement impossible : aucune copie remise', error);
        }
      }
    });
    app.docRegistry.addWidgetExtension('Notebook', {
      createNew(panel) {
        const button = new ToolbarButton({ label: teacher ? 'Version Moodle' : 'Remettre dans Moodle',
          onClick: () => app.commands.execute('moodle:submit') });
        panel.toolbar.insertItem(0, 'moodle-submit', button);
        return { dispose: () => button.dispose(), get isDisposed() { return button.isDisposed; } };
      }
    });
  }
};
