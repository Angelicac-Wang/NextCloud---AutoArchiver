/**
 * 註冊冷宮區視圖到 Files app
 */
import { getNavigation } from '@nextcloud/files'
import { coldPalaceView } from './coldPalaceView.js'

// 註冊視圖
getNavigation().register(coldPalaceView)

console.log('❄️ Cold Palace view registered')

// 監聽視圖切換事件，手動設置 data-app
const checkColdPalaceView = () => {
	const url = window.location.href
	console.log('🔍 Checking URL:', url)

	// 檢查當前是否在冷宮區視圖
	const currentView = getNavigation().active
	console.log('📍 Current view:', currentView ? currentView.id : 'none')

	if (currentView && currentView.id === 'cold_palace') {
		console.log('❄️ Cold Palace view is active, setting data-app')
		document.body.setAttribute('data-app', 'cold_palace')
	} else if (document.body.getAttribute('data-app') === 'cold_palace') {
		// 離開冷宮區時，恢復為 files
		console.log('📁 Leaving Cold Palace, restoring to files')
		document.body.setAttribute('data-app', 'files')
	}
}

// 初始檢查
setTimeout(checkColdPalaceView, 200)

// 監聽 URL 變化
let lastUrl = location.href
setInterval(() => {
	if (location.href !== lastUrl) {
		lastUrl = location.href
		console.log('🔄 URL changed to:', lastUrl)
		checkColdPalaceView()
	}
}, 200)
