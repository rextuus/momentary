const Encore = require('@symfony/webpack-encore');

Encore
    // The public output directory for compiled assets
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    // Define the main entry point for your app
    .addEntry('app', './assets/app.js')

    // Enable Single Runtime Chunk (recommended for most cases)
    .enableSingleRuntimeChunk() // <-- This line fixes the error

    // Optional: Automatically provide jQuery or other global variables

    // Enable SCSS/SASS processing
    .enableSassLoader()

    // Enable Source Maps (useful for debugging)
    .enableSourceMaps(!Encore.isProduction())

    // Enable hashed filenames (recommended for production)
    .enableVersioning(Encore.isProduction())

    // Clean the output directory before building
    .cleanupOutputBeforeBuild()

    .enableStimulusBridge('./assets/controllers.json')

;

module.exports = Encore.getWebpackConfig();