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
    // Bettercap's REST API and its browsable web UI are two SEPARATE modules/ports,
    // not one -- `api.rest on` alone never serves a browsable page (confirmed: GET /
    // on the REST port returns a bare 405). The UI module (`ui on`) defaults to
    // ui.address=127.0.0.1, which is loopback-only on the router itself and
    // unreachable from any other machine's browser -- it must be explicitly rebound
    // to 0.0.0.0, same as the REST API already is.
    const REST_API_PORT = 8083;
    const WEB_UI_PORT = 8080;
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

    // nohup (via execBackground) leaves bettercap's stdin inherited as a closed/EOF
    // fd (no real terminal) -- its interactive readline loop treats that EOF as the
    // user hitting quit, triggers its "Are you sure you want to quit this session?"
    // confirmation, and dies seconds after starting. A FIFO kept open for writing by
    // a long-lived `tail -f /dev/null` (writes nothing, never exits) gives bettercap
    // a stdin that blocks forever instead of hitting EOF, so its console loop idles
    // normally in the background the same way it would behind a real terminal.
    const STDIN_FIFO_PATH = '/tmp/fm-bettercap.stdin';
    const STDIN_WRITER_PID_PATH = '/tmp/fm-bettercap-stdin-writer.pid';

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
        $homeDir = $this->homeDir();

        // -eval runs these in bettercap's own interactive session before it settles;
        // separated by ';' per bettercap's own syntax, not shell syntax -- do not
        // escapeshellarg the individual commands, only the whole -eval argument.
        // Handshakes/events persisted under homeDir() (USB when mounted, same as the
        // REST credentials file) instead of bettercap's own default of ~ (internal
        // flash), matching every other module's data on this device.
        $evalCommands = sprintf(
            'set api.rest.address 0.0.0.0; set api.rest.port %d; set api.rest.username %s; set api.rest.password %s; api.rest on; set ui.address 0.0.0.0; set ui.port %d; ui on; set wifi.handshakes.file %s/handshakes.pcap; set events.stream.output %s/events.log',
            self::REST_API_PORT,
            self::WEB_UI_USERNAME,
            $password,
            self::WEB_UI_PORT,
            $homeDir,
            $homeDir
        );

        file_put_contents(self::LOG_PATH, '');
        file_put_contents(
            self::RUN_SCRIPT_PATH,
            sprintf(
                "#!/bin/sh\nrm -f %s\nmkfifo %s\ntail -f /dev/null > %s &\necho \$! > %s\n%s -iface %s -no-colors -eval %s < %s >> %s 2>&1\n",
                escapeshellarg(self::STDIN_FIFO_PATH),
                escapeshellarg(self::STDIN_FIFO_PATH),
                escapeshellarg(self::STDIN_FIFO_PATH),
                escapeshellarg(self::STDIN_WRITER_PID_PATH),
                escapeshellarg($this->binPath()),
                escapeshellarg($interface),
                escapeshellarg($evalCommands),
                escapeshellarg(self::STDIN_FIFO_PATH),
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

        // Clean up the stdin-keepalive writer from startCapture() -- it would
        // otherwise sit around forever once bettercap itself has stopped.
        if (file_exists(self::STDIN_WRITER_PID_PATH)) {
            $pid = trim(file_get_contents(self::STDIN_WRITER_PID_PATH));
            if (ctype_digit($pid)) {
                OpenWrtHelper::exec("kill -9 {$pid}");
            }
            unlink(self::STDIN_WRITER_PID_PATH);
        }
        if (file_exists(self::STDIN_FIFO_PATH)) {
            unlink(self::STDIN_FIFO_PATH);
        }

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
