'use strict';

/* globals context, slider */

function initIconFetcher() {
	const form = document.querySelector('form[data-icon-fetcher]');
	if (!form || form.dataset.iconFetcherReady === '1') {
		return;
	}
	form.dataset.iconFetcherReady = '1';

	const status = form.querySelector('#icon_fetcher_status');
	const buttons = form.querySelectorAll('[data-icon-action]');
	const csrf = (typeof context !== 'undefined' && context.csrf)
		? context.csrf
		: form.querySelector('input[name="_csrf"]').value;

	const post = async (action, args = {}) => {
		const response = await fetch(form.action, {
			method: 'POST',
			body: JSON.stringify({
				_csrf: csrf,
				icon_action: action,
				...args,
			}),
			headers: {
				'Content-Type': 'application/json; charset=UTF-8',
			},
		});
		if (!response.ok) {
			throw new Error(`HTTP ${response.status}`);
		}
		return response.json();
	};

	const setDisabled = (disabled) => buttons.forEach((button) => { button.disabled = disabled; });

	const run = async (force) => {
		setDisabled(true);
		status.textContent = '…';
		try {
			const feeds = await post('ajax_list_feeds');
			let completed = 0;
			let succeeded = 0;
			for (const feed of feeds) {
				const result = await post('ajax_fetch_icon', {
					id: feed.id,
					force: force ? '1' : '0',
				});
				if (result && result.ok) {
					succeeded += 1;
				}
				completed += 1;
				status.textContent = `${completed}/${feeds.length} · ${feed.title}`;
			}
			status.textContent = `${succeeded}/${feeds.length}`;
		} catch (error) {
			status.textContent = `Error: ${error.message}`;
		} finally {
			setDisabled(false);
		}
	};

	form.querySelector('[data-icon-action="fetch_missing"]').addEventListener('click', () => run(false));
	form.querySelector('[data-icon-action="refresh_all"]').addEventListener('click', () => run(true));
}

window.addEventListener('load', initIconFetcher);
if (typeof slider !== 'undefined') {
	slider.addEventListener('freshrss:slider-load', initIconFetcher);
}
