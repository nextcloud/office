/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* global process */

import {
	configureNextcloud,
	docker,
	getContainer,
	runOcc,
	setSystemConfig,
	startNextcloud,
	stopNextcloud,
	waitOnNextcloud,
} from '@nextcloud/e2e-test-server/docker'
import { resolve } from 'node:path'

const NEXTCLOUD_PORT = 8089
const COLLABORA_PORT = Number(process.env.COLLABORA_PORT ?? 9980)
const COLLABORA_IMAGE = 'collabora/code:latest'
const COLLABORA_CONTAINER = 'nextcloud-e2e-office-collabora'
// Set to a local checkout (with built js/ and vendor/) to test against it
// instead of the app store release or the Text main branch.
const RICHDOCUMENTS_PATH = process.env.RICHDOCUMENTS_PATH
const TEXT_PATH = process.env.TEXT_PATH

async function isUp(url) {
	try {
		return (await fetch(url)).ok
	} catch {
		return false
	}
}

// Both containers only run together after a completed setup: a failed or
// interrupted one removes them again.
async function isServerRunning() {
	return await isUp(`http://127.0.0.1:${NEXTCLOUD_PORT}/status.php`)
		&& await isUp(`http://127.0.0.1:${COLLABORA_PORT}/hosting/discovery`)
}

async function bridgeIp(container) {
	const { NetworkSettings } = await container.inspect()
	return NetworkSettings.Networks.bridge.IPAddress
}

async function pull(image) {
	const stream = await docker.pull(image)
	await new Promise((resolve, reject) => docker.modem.followProgress(stream, (err) => err ? reject(err) : resolve()))
}

async function removeCollabora() {
	await docker.getContainer(COLLABORA_CONTAINER).remove({ force: true }).catch(() => {})
}

// Collabora calls back into Nextcloud for WOPI and Nextcloud fetches its
// discovery, so both talk over the docker bridge; the browser reaches Collabora
// through the published port.
async function startCollabora(nextcloudIp) {
	console.log('Starting Collabora container… 🚀')
	await pull(COLLABORA_IMAGE)
	await removeCollabora()
	const container = await docker.createContainer({
		Image: COLLABORA_IMAGE,
		name: COLLABORA_CONTAINER,
		Env: [
			`aliasgroup1=http://${nextcloudIp}`,
			// The URL Collabora advertises in its discovery, which the browser loads.
			`server_name=localhost:${COLLABORA_PORT}`,
			'extra_params=--o:ssl.enable=false --o:home_mode.enable=true',
		],
		HostConfig: {
			PortBindings: { '9980/tcp': [{ HostPort: String(COLLABORA_PORT) }] },
		},
	})
	await container.start()

	for (let tries = 0; tries < 60; tries++) {
		if (await isUp(`http://127.0.0.1:${COLLABORA_PORT}/hosting/discovery`)) {
			console.log('└─ Collabora is ready')
			return bridgeIp(container)
		}
		await new Promise((resolve) => setTimeout(resolve, 2000))
	}
	throw new Error('Collabora did not become ready')
}

async function configureRichdocuments(nextcloudIp, collaboraIp) {
	if (!RICHDOCUMENTS_PATH) {
		// No release targets the server's development branch yet, hence --force.
		await runOcc(['app:install', '--allow-unstable', '--force', 'richdocuments'], { verbose: true })
	}
	await runOcc(['app:enable', '--force', 'richdocuments'], { verbose: true })

	await setSystemConfig('allow_local_remote_servers', 'true')
	await runOcc(['config:system:set', 'trusted_domains', '1', `--value=${nextcloudIp}`])
	await runOcc(['config:app:set', 'richdocuments', 'wopi_allowlist', `--value=${collaboraIp}`])
	await runOcc([
		'richdocuments:activate-config',
		`--wopi-url=http://${collaboraIp}:9980`,
		`--callback-url=http://${nextcloudIp}/`,
	], { verbose: true })
}

async function start() {
	const mounts = {
		...(RICHDOCUMENTS_PATH && { 'apps-writable/richdocuments': resolve(RICHDOCUMENTS_PATH) }),
		...(TEXT_PATH && { 'apps-writable/text': resolve(TEXT_PATH) }),
	}
	// Never reuse a leftover Nextcloud container: it may be half configured.
	const ip = await startNextcloud('master', true, { exposePort: NEXTCLOUD_PORT, mounts, forceRecreate: true })
	await waitOnNextcloud(ip)
	await configureNextcloud(['text', 'office'])

	const nextcloudIp = await bridgeIp(getContainer())
	const collaboraIp = await startCollabora(nextcloudIp)
	await configureRichdocuments(nextcloudIp, collaboraIp)
	console.log('└─ Office e2e environment is ready')
}

// Only tear down what this process started, so an already running server
// survives a test run that merely reused it.
let started = false

async function stop(exitCode = 0) {
	if (started) {
		process.stderr.write('Stopping Nextcloud server…\n')
		await removeCollabora()
		await stopNextcloud()
	}
	process.exit(exitCode)
}

process.on('SIGTERM', () => stop())
process.on('SIGINT', () => stop())

if (await isServerRunning()) {
	console.log('└─ Office e2e environment is ready')
} else {
	started = true
	await start().catch(async (error) => {
		console.error(error)
		await stop(1)
	})
}

// Idle to wait for shutdown
while (true) {
	await new Promise((resolve) => setTimeout(resolve, 5000))
}
