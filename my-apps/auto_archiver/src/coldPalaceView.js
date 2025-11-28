/**
 * 冷宮區 - Files app 左側欄視圖
 */
import { View, getNavigation } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { getContents } from './services/archive.js'

export const COLD_PALACE_VIEW_ID = 'cold_palace'

export const coldPalaceView = new View({
	id: COLD_PALACE_VIEW_ID,
	name: t('auto_archiver', '冷宮區'),
	caption: t('auto_archiver', '已封存的檔案列表'),

	emptyTitle: t('auto_archiver', '無封存檔案'),
	emptyCaption: t('auto_archiver', '被封存的檔案會顯示在這裡'),

	icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20,6H12L10,4H4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8A2,2 0 0,0 20,6M19,18H5V8H19V18M12,10V14L14.5,11.5L15.92,12.92L12,16.84L8.08,12.92L9.5,11.5L12,14V10Z"/></svg>',
	order: 25, // 在 Files (0) 和 Trashbin (50) 之間
	sticky: false,

	getContents,
})
