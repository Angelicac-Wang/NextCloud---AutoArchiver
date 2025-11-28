const path = require('path')

module.exports = {
	entry: {
		'files-init': path.join(__dirname, 'src', 'files-init.js'),
	},
	output: {
		path: path.resolve(__dirname, 'js'),
		filename: '[name].js',
		clean: false, // 不要刪除其他 js 檔案
	},
	module: {
		rules: [
			{
				test: /\.js$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: ['@babel/preset-env'],
					},
				},
			},
		],
	},
	resolve: {
		extensions: ['.js'],
		fallback: {
			// Webpack 5 需要明確配置 Node.js polyfills
			path: require.resolve('path-browserify'),
			string_decoder: false, // 不需要此模組
		},
	},
}
