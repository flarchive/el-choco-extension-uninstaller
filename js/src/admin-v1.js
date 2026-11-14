import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import createUninstallerPage from './UninstallerPage';

const UninstallerPage = createUninstallerPage(app, ExtensionPage, m);
// Export a real module object, including an extender list, for bootExtensions.
export const extend = [];
app.initializers.add('el-choco-extension-uninstaller', () => {
  app.extensionData.for('el-choco-extension-uninstaller').registerPage(UninstallerPage);
});
