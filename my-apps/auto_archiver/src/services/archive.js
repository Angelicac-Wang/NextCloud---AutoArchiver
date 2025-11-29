/**
 * 冷宮區 - 取得封存檔案列表
 */
import { davGetClient, davGetDefaultPropfind, davResultToNode, davRootPath } from '@nextcloud/files'

export const getContents = async (path = '/') => {
	const davClient = davGetClient()

	// 冷宮區永遠只顯示 /Archive 資料夾的內容（封存檔案存放處）
	// 如果 path 是 '/'，顯示 /Archive 根目錄
	// 如果 path 是子路徑，顯示 /Archive 下的子路徑
	const targetPath = path === '/' ? '/Archive' : `/Archive${path}`
	const archivePath = `${davRootPath}${targetPath}`

	console.log('🔍 Cold Palace - fetching path:', targetPath)

	try {
		const response = await davClient.getDirectoryContents(archivePath, {
			details: true,
			data: davGetDefaultPropfind(),
			includeSelf: true,
		})

		// 需要傳入 davRootPath 作為第二個參數
		const contents = response.data.map(stat => davResultToNode(stat, davRootPath))

		// 找到資料夾本身（includeSelf 會包含當前目錄）
		const folderIndex = contents.findIndex(node => node.path === targetPath)
		const folder = folderIndex >= 0 ? contents.splice(folderIndex, 1)[0] : null

		console.log('✅ Cold Palace - found', contents.length, 'items in', targetPath)

		return {
			folder,
			contents,
		}
	} catch (error) {
		// 如果 archive 資料夾不存在，返回空列表
		console.warn('Archive folder not found:', error)
		return {
			folder: null,
			contents: [],
		}
	}
}
