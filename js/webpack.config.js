const path = require('node:path');
const current = require('flarum-webpack-config');
const legacy = require('flarum-webpack-config-legacy');

function target(factory, version) {
  const config = factory();
  config.name = 'flarum-' + version;
  config.entry = { ['admin-v' + version]: './src/admin-v' + version + '.js' };
  config.output = { ...config.output, path: path.resolve(__dirname, 'dist'), filename: '[name].js', clean: false };
  if (version === 1) {
    // Native subclassing also works if a Flarum 1 build exposes native classes.
    // Babel's loose .call() subclassing cannot extend a native class.
    for (const rule of config.module.rules) {
      if (!rule.options || !rule.options.presets) continue;
      rule.options = { ...rule.options, presets: rule.options.presets.map(preset =>
        Array.isArray(preset) && preset[0].includes('preset-env')
          ? [preset[0], { ...preset[1], exclude: ['@babel/plugin-transform-classes'] }]
          : preset
      ) };
    }
  }
  return config;
}
module.exports = [target(legacy, 1), target(current, 2)];
