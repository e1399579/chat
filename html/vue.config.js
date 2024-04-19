const { defineConfig } = require('@vue/cli-service')
const fs = require('fs')
const devServer = process.env.SSL_CERT && process.env.SSL_KEY
  ? {
    https: {
      cert: fs.readFileSync(process.env.SSL_CERT),
      key: fs.readFileSync(process.env.SSL_KEY),
    },
  }
  : {};
module.exports = defineConfig({
    transpileDependencies: true,
    publicPath: process.env.PUBLIC_PATH ? process.env.PUBLIC_PATH : '/',
    devServer: devServer
})
