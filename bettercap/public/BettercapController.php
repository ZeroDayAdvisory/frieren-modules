<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\bettercap;

use frieren\helper\OpenWrtHelper;

class BettercapController extends \frieren\core\Controller
{
    // Bettercap's own REST API + web UI (both embedded in the binary via Go's
    // go:embed -- no separate static-asset directory needed, unlike kismet).
    // Bound to 0.0.0.0 so it's reachable from a browser on the LAN, same as how
    // kismet's own httpd ships bound to 0.0.0.0 by default.
    const WEB_UI_PORT = 8083;
    const WEB_UI_USERNAME = 'bettercap';

    // tmpfs: ephemeral, cleared on reboot.
    const LOG_PATH = '/tmp/fm-bettercap.log';
    const RUN_SCRIPT_PATH = '/tmp/fm-bettercap-run.sh';

    // Persistent across module updates/reinstalls on purpose, same rationale as
    // kismet's homedir: holds the one generated REST password so a restart doesn't
    // silently rotate it and lock the operator out of a session they already opened.
    //
    // On USB storage when it's actually mounted, same reasoning and fallback as
    // kismet's homeDir() -- see that controller for why.
    const USB_HOME_DIR = '/mnt/usb/frieren/bettercap';
    const INTERNAL_HOME_DIR = '/root/.bettercap';

    const INTERFACE_NAME_REGEX = '/^[A-Za-z0-9_.-]{1,32}$/';

    protected $endpointRoutes = [
        'moduleStatus' => true,
        'startCapture' => true,
        'stopCapture' => true,
        'getLogContent' => true,
    ];

    private function binPath()
    {
        return self::getModulePath() . '/bin/bettercap';
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

    private function credentialsFile()
    {
        return $this->homeDir() . '/rest_password';
    }

    /**
     * Returns the REST API password, generating and persisting a new random one
     * on first use. Kept stable across restarts so the operator's saved browser
     * login to the native UI keeps working.
     */
    private function restPassword()
    {
        $credentialsFile = $this->credentialsFile();
        if (file_exists($credentialsFile)) {
            $password = trim(file_get_contents($credentialsFile));
            if ($password !== '') {
                return $password;
            }
        }

        $homeDir = $this->homeDir();
        if (!file_exists($homeDir)) {
            mkdir($homeDir, 0700, true);
        }

        $password = bin2hex(random_bytes(12));
        file_put_contents($credentialsFile, $password);
        chmod($credentialsFile, 0600);

        return $password;
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
            'webUiUsername' => self::WEB_UI_USERNAME,
            // Only surfaced once a session has actually generated it -- this is a
            // real credential, not a placeholder, so we don't invent one just to
            // answer a status check.
            'webUiPassword' => file_exists($this->credentialsFile())
                ? trim(file_get_contents($this->credentialsFile()))
                : null,
        ]);
    }

    public function startCapture()
    {
        if (!$this->isInstalled()) {
            return self::setError('Bettercap binary not found');
        }

        if ($this->isRunning()) {
            return self::setError('Bettercap is already running');
        }

        $interface = $this->request['interface'] ?? '';
        if (empty($interface) || !preg_match(self::INTERFACE_NAME_REGEX, $interface)) {
            return self::setError('Invalid or missing network interface');
        }

        $password = $this->restPassword();

        // -eval runs these in bettercap's own interactive session before it settles;
        // separated by ';' per bettercap's own syntax, not shell syntax -- do not
        // escapeshellarg the individual commands, only the whole -eval argument.
        $evalCommands = sprintf(
            'set api.rest.address 0.0.0.0; set api.rest.port %d; set api.rest.username %s; set api.rest.password %s; api.rest on',
            self::WEB_UI_PORT,
            self::WEB_UI_USERNAME,
            $password
        );

        file_put_contents(self::LOG_PATH, '');
        file_put_contents(
            self::RUN_SCRIPT_PATH,
            sprintf(
                "#!/bin/sh\n%s -iface %s -no-colors -eval %s >> %s 2>&1\n",
                escapeshellarg($this->binPath()),
                escapeshellarg($interface),
                escapeshellarg($evalCommands),
                escapeshellarg(self::LOG_PATH)
            )
        );

        $this->logger("bettercap started on interface {$interface}", 'info');
        OpenWrtHelper::execBackground('sh ' . escapeshellarg(self::RUN_SCRIPT_PATH));

        return self::setSuccess(['started' => true]);
    }

    public function stopCapture()
    {
        // No pkill on this image -- killall matches by process name, unambiguous
        // here since nothing else on the device is named "bettercap".
        OpenWrtHelper::exec('killall -9 bettercap');

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

        // Drain: read + truncate, same pattern as kismet/hcxdumptool's log viewers.
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
