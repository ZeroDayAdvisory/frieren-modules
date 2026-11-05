/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-toastify';
import { fetchPost } from '@common/services/fetchService.js';
import PanelCard from '@common/components/PanelCard';
import PanelStack from '@common/components/PanelCard/PanelStack';
import LogView from '@common/components/LogView';

const STATUS_QUERY_KEY = ['mdk4', 'moduleStatus'];
const LOG_QUERY_KEY = ['mdk4', 'getLogContent'];
const STATUS_POLL_MS = 5000;
const LOG_POLL_MS = 1000;

const MODE_LABELS = {
    b: 'Beacon Flooding',
    a: 'Authentication DoS',
    p: 'SSID Probing',
    d: 'Deauthentication',
};

/**
 * mdk4 module panel: pick a monitor-mode interface + attack mode, start/stop, and
 * watch the live output. Unlike kismet/bettercap, mdk4 has no REST API/web UI of its
 * own and prints continuously while running, so this panel IS the whole interface --
 * no "open native UI" link, just a live log.
 */
const Mdk4Page = () => {
    const queryClient = useQueryClient();
    const [selectedInterface, setSelectedInterface] = useState('');
    const [selectedMode, setSelectedMode] = useState('');
    const [extraArgs, setExtraArgs] = useState('');
    const [logTick, setLogTick] = useState(0);
    const [logChunk, setLogChunk] = useState('');

    const { data: status, isLoading } = useQuery({
        queryKey: STATUS_QUERY_KEY,
        queryFn: () => fetchPost({ module: 'mdk4', action: 'moduleStatus' }),
        refetchInterval: STATUS_POLL_MS,
    });

    const isRunning = status?.isRunning ?? false;

    useQuery({
        queryKey: LOG_QUERY_KEY,
        queryFn: async () => {
            const result = await fetchPost({ module: 'mdk4', action: 'getLogContent' });
            if (result.chunk) {
                setLogChunk(result.chunk);
                setLogTick((prev) => prev + 1);
            }

            return result;
        },
        refetchInterval: isRunning ? LOG_POLL_MS : false,
        enabled: isRunning,
    });

    const startMutation = useMutation({
        mutationFn: () => fetchPost({
            module: 'mdk4',
            action: 'startAttack',
            interface: selectedInterface,
            mode: selectedMode,
            extraArgs,
        }),
        onSuccess: () => {
            toast.success('mdk4 started');
            setLogChunk('');
            queryClient.invalidateQueries({ queryKey: STATUS_QUERY_KEY });
        },
        onError: (error) => toast.error(error.message),
    });

    const stopMutation = useMutation({
        mutationFn: () => fetchPost({ module: 'mdk4', action: 'stopAttack' }),
        onSuccess: () => {
            toast.success('mdk4 stopped');
            queryClient.invalidateQueries({ queryKey: STATUS_QUERY_KEY });
        },
        onError: (error) => toast.error(error.message),
    });

    if (isLoading) {
        return <PanelCard title="MDK4">Loading...</PanelCard>;
    }

    if (!status?.hasDependencies) {
        return (
            <PanelCard title="MDK4">
                <p className="text-danger mb-0">
                    mdk4 binary not found. This module ships its own binary under
                    <code> bin/mdk4</code> — reinstall the module if it's missing.
                </p>
            </PanelCard>
        );
    }

    const interfaces = status.interfaces ?? [];
    const allowedModes = status.allowedModes ?? Object.keys(MODE_LABELS);

    return (
        <PanelStack>
            <PanelCard title="MDK4">
                <p className="text-muted small">
                    The chosen interface must already be in monitor mode — mdk4 transmits raw
                    802.11 frames and won't work on a managed/AP interface.
                </p>

                <p>
                    Status:{' '}
                    <strong className={isRunning ? 'text-success' : 'text-secondary'}>
                        {isRunning ? 'Running' : 'Stopped'}
                    </strong>
                </p>

                {!isRunning && (
                    <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <select
                            className="form-select"
                            style={{ maxWidth: 220 }}
                            value={selectedInterface}
                            onChange={(e) => setSelectedInterface(e.target.value)}
                        >
                            <option value="">Monitor interface...</option>
                            {interfaces.map((ifname) => (
                                <option key={ifname} value={ifname}>{ifname}</option>
                            ))}
                        </select>
                        <select
                            className="form-select"
                            style={{ maxWidth: 220 }}
                            value={selectedMode}
                            onChange={(e) => setSelectedMode(e.target.value)}
                        >
                            <option value="">Attack mode...</option>
                            {allowedModes.map((mode) => (
                                <option key={mode} value={mode}>{MODE_LABELS[mode] ?? mode}</option>
                            ))}
                        </select>
                        <input
                            type="text"
                            className="form-control"
                            style={{ maxWidth: 220 }}
                            placeholder="Extra args (optional)"
                            value={extraArgs}
                            onChange={(e) => setExtraArgs(e.target.value)}
                        />
                        <button
                            className="btn btn-primary text-nowrap"
                            disabled={!selectedInterface || !selectedMode || startMutation.isPending}
                            onClick={() => startMutation.mutate()}
                        >
                            Start
                        </button>
                    </div>
                )}

                {isRunning && (
                    <div className="mb-3">
                        <button
                            className="btn btn-danger"
                            disabled={stopMutation.isPending}
                            onClick={() => stopMutation.mutate()}
                        >
                            Stop
                        </button>
                    </div>
                )}

                <LogView chunk={logChunk} tick={logTick} clearSignal={!isRunning} height={320} />
            </PanelCard>
        </PanelStack>
    );
};

export default Mdk4Page;
