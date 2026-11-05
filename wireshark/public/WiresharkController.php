<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\wireshark;

use frieren\helper\OpenWrtHelper;

class WiresharkController extends \frieren\core\Controller
{
    // rpcapd is libpcap's remote-capture daemon -- real desktop Wireshark connects to
    // it directly over the network (Capture > Options > Manage Interfaces > Remote) and
    // picks an interface itself once connected, so this module never needs to know or
    // offer an interface list. No capture/analysis binary of any kind runs on the router.
    const RPCAPD_PORT = 2002;

    protected $endpointRoutes = [
        'installModuleDependencies' => true,
        'getDependencyInstallationStatus' => true,
        'moduleStatus' => true,
        'startDaemon' => true,
        'stopDaemon' => true,
    ];

    private function isInstalled()
    {
        return OpenWrtHelper::commandExists('rpcapd');
    }

    private function isRunning()
    {
        return $this->isInstalled() && OpenWrtHelper::checkRunning('rpcapd');
    }

    public function moduleStatus()
    {
        if (!$this->isInstalled()) {
            return self::setSuccess([
                'hasDependencies' => false,
                'isRunning' => false,
                'internalAvailable' => (disk_free_space('/') > self::MIN_DISK_SPACE) && \DeviceConfig::MODULE_USE_INTERNAL_STORAGE,
                'SDAvailable' => OpenWrtHelper::isSDAvailable() && \DeviceConfig::MODULE_USE_USB_STORAGE,
            ]);
        }

        return self::setSuccess([
            'hasDependencies' => true,
            'isRunning' => $this->isRunning(),
            'port' => self::RPCAPD_PORT,
        ]);
    }

    public function startDaemon()
    {
        if (!$this->isInstalled()) {
            return self::setError('rpcapd binary not found');
        }

        if ($this->isRunning()) {
            return self::setError('rpcapd is already running');
        }

        // -n: permit NULL authentication -- this device's exposed services (bettercap's
        // REST API, kismet/mdk4's panels) already share this same trust model: reachable
        // to anyone on the LAN, no separate credential layer of their own.
        $this->logger('rpcapd remote capture daemon started', 'info');
        OpenWrtHelper::execBackground(sprintf('rpcapd -n -p %d', self::RPCAPD_PORT));

        return self::setSuccess(['started' => true]);
    }

    public function stopDaemon()
    {
        OpenWrtHelper::exec('killall -9 rpcapd');

        return self::setSuccess([
            'success' => !$this->isRunning(),
        ]);
    }
}
