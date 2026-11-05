<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\mdk4;

use frieren\helper\OpenWrtHelper;

class Mdk4Controller extends \frieren\core\Controller
{
    // tmpfs: ephemeral, cleared on reboot.
    const LOG_PATH = '/tmp/fm-mdk4.log';
    const RUN_SCRIPT_PATH = '/tmp/fm-mdk4-run.sh';

    const INTERFACE_NAME_REGEX = '/^[A-Za-z0-9_.-]{1,32}$/';

    // Scoped to the attack modes with a simple, well-understood "no extra args
    // required" default behavior. mdk4 has more modes (s/w/f/e/m/x) but each needs
    // very different mode-specific option sets -- left out of v1 rather than
    // modeled half-right.
    const ALLOWED_MODES = ['b', 'a', 'p', 'd'];

    protected $endpointRoutes = [
        'moduleStatus' => true,
        'startAttack' => true,
        'stopAttack' => true,
        'getLogContent' => true,
    ];

    private function binPath()
    {
        return self::getModulePath() . '/bin/mdk4';
    }

    private function isInstalled()
    {
        $bin = $this->binPath();

        return file_exists($bin) && is_executable($bin);
    }

    private function isRunning()
    {
        return $this->isInstalled() && OpenWrtHelper::checkRunning($this->binPath(), true);
    }

    public function moduleStatus()
    {
        if (!$this->isInstalled()) {
            return self::setSuccess([
                'hasDependencies' => false,
                'isRunning' => false,
            ]);
        }

        return self::setSuccess([
            'hasDependencies' => true,
            'isRunning' => $this->isRunning(),
            'interfaces' => self::setupModuleHelper()::getNetworkInterfaces(),
            'allowedModes' => self::ALLOWED_MODES,
        ]);
    }

    public function startAttack()
    {
        if (!$this->isInstalled()) {
            return self::setError('mdk4 binary not found');
        }

        if ($this->isRunning()) {
            return self::setError('mdk4 is already running');
        }

        $interface = $this->request['interface'] ?? '';
        if (empty($interface) || !preg_match(self::INTERFACE_NAME_REGEX, $interface)) {
            return self::setError('Invalid or missing interface (must be a monitor-mode interface)');
        }

        $mode = $this->request['mode'] ?? '';
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            return self::setError('Invalid attack mode');
        }

        // Mode-specific flags (e.g. "-c 6" for a channel, "-s 1000" for packet rate,
        // a target BSSID for deauth) vary too much per mode to model individually --
        // same approach hcxdumptool's module takes for its own free-form command tail.
        // escapeshellcmd (not escapeshellarg) so legitimate multi-flag strings survive;
        // it still strips shell metacharacters that would otherwise break out.
        $extraArgs = escapeshellcmd(trim($this->request['extraArgs'] ?? ''));

        file_put_contents(self::LOG_PATH, '');
        file_put_contents(
            self::RUN_SCRIPT_PATH,
            sprintf(
                "#!/bin/sh\n%s %s %s %s >> %s 2>&1\n",
                escapeshellarg($this->binPath()),
                escapeshellarg($interface),
                escapeshellarg($mode),
                $extraArgs,
                escapeshellarg(self::LOG_PATH)
            )
        );

        $this->logger("mdk4 attack '{$mode}' started on interface {$interface}", 'info');
        OpenWrtHelper::execBackground('sh ' . escapeshellarg(self::RUN_SCRIPT_PATH));

        return self::setSuccess(['started' => true]);
    }

    public function stopAttack()
    {
        // No pkill on this image -- killall matches by process name, unambiguous
        // here since nothing else on the device is named "mdk4".
        OpenWrtHelper::exec('killall -9 mdk4');

        return self::setSuccess([
            'success' => !$this->isRunning(),
        ]);
    }

    public function getLogContent()
    {
        if (!file_exists(self::LOG_PATH)) {
            return self::setSuccess([
                'isRunning' => $this->isRunning(),
                'chunk' => '',
            ]);
        }

        // Drain: read + truncate. Unlike kismet/bettercap, mdk4 runs in the foreground
        // and prints continuously (packet counts, status lines) for as long as it's
        // attacking, so this really is a live tail, not a one-time startup banner.
        $chunk = '';
        $handle = fopen(self::LOG_PATH, 'r+');
        if ($handle !== false) {
            $chunk = stream_get_contents($handle);
            ftruncate($handle, 0);
            fclose($handle);
        }

        return self::setSuccess([
            'isRunning' => $this->isRunning(),
            'chunk' => $chunk,
        ]);
    }
}
