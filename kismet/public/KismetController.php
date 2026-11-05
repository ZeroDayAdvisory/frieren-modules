<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\kismet;

use frieren\helper\OpenWrtHelper;

class KismetController extends \frieren\core\Controller
{
    // Kismet's own httpd runs here (its native web UI / REST API). We don't proxy
    // or re-implement it -- the frontend just links out to it once kismet is running.
    const WEB_UI_PORT = 2501;

    // tmpfs: ephemeral, cleared on reboot. Only the pre-daemonize startup output lands
    // here -- kismet goes silent on its own stdout/stderr once it forks (that's what
    // -s/--silent means: its real logging lives in its own homedir db, not a tailable
    // text stream), so this is a one-shot "did it start cleanly" log, not a live feed.
    const LOG_PATH = '/tmp/fm-kismet.log';
    const RUN_SCRIPT_PATH = '/tmp/fm-kismet-run.sh';

    // Persistent across module updates/reinstalls on purpose: this is where kismet
    // keeps devicetracker.db3 (tracked devices/alerts) and its generated server UUID +
    // REST credentials. Deliberately outside the module's own folder, which an update
    // or reinstall replaces wholesale.
    //
    // On USB storage when it's actually mounted (devicetracker.db3 grows unbounded
    // over a long-running capture -- the ~7GB internal overlay is the wrong place for
    // that), falling back to internal storage if no USB drive is present so the
    // module still works on a device without one.
    const USB_HOME_DIR = '/mnt/usb/frieren/kismet';
    const INTERNAL_HOME_DIR = '/root/.kismet';

    // Capture interface names are short, kernel-assigned netdev names (wlan0, wlan2mon,
    // phy6-ap0, ...). Reject anything else before it reaches a shell command.
    const INTERFACE_NAME_REGEX = '/^[A-Za-z0-9_.-]{1,32}$/';

    protected $endpointRoutes = [
        'moduleStatus' => true,
        'startCapture' => true,
        'stopCapture' => true,
        'getLogContent' => true,
    ];

    private function binPath()
    {
        return self::getModulePath() . '/bin/kismet';
    }

    /**
     * Resolves to USB storage when actually mounted, internal storage otherwise.
     * Checked live (not cached) since the drive can be plugged/unplugged between
     * requests, and reading /proc/mounts is cheap.
     */
    private function homeDir()
    {
        $mounts = @file_get_contents('/proc/mounts');
        if ($mounts !== false && preg_match('#\s/mnt/usb\s#', $mounts)) {
            return self::USB_HOME_DIR;
        }

        return self::INTERNAL_HOME_DIR;
    }

    private function confDir()
    {
        return self::getModulePath() . '/conf';
    }

    private function dataDir()
    {
        // Kismet appends "kismet/" itself (e.g. looks for "<datadir>/kismet/httpd/" per
        // kismet_httpd.conf's httpd_home=%S/kismet/httpd/, and "<datadir>/kismet/
        // kismet_manuf.txt.gz"), so this points one level above the bundled
        // share/kismet/ directory, not at it directly.
        return self::getModulePath() . '/share';
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
            'webUiPort' => self::WEB_UI_PORT,
        ]);
    }

    public function startCapture()
    {
        if (!$this->isInstalled()) {
            return self::setError('Kismet binary not found');
        }

        if ($this->isRunning()) {
            return self::setError('Kismet is already running');
        }

        $interface = $this->request['interface'] ?? '';
        if (empty($interface) || !preg_match(self::INTERFACE_NAME_REGEX, $interface)) {
            return self::setError('Invalid or missing capture interface');
        }

        $homeDir = $this->homeDir();
        if (!file_exists($homeDir)) {
            mkdir($homeDir, 0700, true);
        }

        // Launcher script pattern (matches hcxdumptool): the redirect lives inside the
        // script, not in execBackground's own redirect param, so both stdout and stderr
        // of the *actual* kismet invocation land in the log regardless of how the shell
        // wrapping nohup around the script handles its own (empty) output.
        file_put_contents(self::LOG_PATH, '');
        file_put_contents(
            self::RUN_SCRIPT_PATH,
            sprintf(
                "#!/bin/sh\n%s --daemonize --no-ncurses --homedir %s --confdir %s --datadir %s -c %s -s >> %s 2>&1\n",
                escapeshellarg($this->binPath()),
                escapeshellarg($homeDir),
                escapeshellarg($this->confDir()),
                escapeshellarg($this->dataDir()),
                escapeshellarg($interface),
                escapeshellarg(self::LOG_PATH)
            )
        );

        $this->logger("kismet started on interface {$interface}", 'info');
        OpenWrtHelper::execBackground('sh ' . escapeshellarg(self::RUN_SCRIPT_PATH));

        return self::setSuccess(['started' => true]);
    }

    public function stopCapture()
    {
        // No pkill on this image -- killall (matching the project's own convention,
        // e.g. hcxdumptool's stopCapture) matches by process name, which is unambiguous
        // here since nothing else on the device is named "kismet".
        OpenWrtHelper::exec('killall -9 kismet');

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

        // Drain: read whatever's accumulated, then truncate, same as hcxdumptool's log
        // viewer. Kismet's own output stops almost immediately after it daemonizes, so
        // in practice this chunk is just the one-time startup banner/errors, not a
        // continuous stream -- the frontend should treat it as a startup diagnostic.
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
