// Entry of the settings pages: mounts the personal page or the admin page.
import { createApp } from 'vue'
import Admin from './views/Admin.vue'
import Settings from './views/Settings.vue'

// One bundle, two settings pages: personal and admin each mount their own div.
for (const [selector, view] of [['#chat_archive_settings', Settings], ['#chat_archive_admin', Admin]]) {
	if (document.querySelector(selector)) {
		createApp(view).mount(selector)
	}
}
