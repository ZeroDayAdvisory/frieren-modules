/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-toastify';
import { fetchPost } from '@common/services/fetchService.js';
import DependenciesAlert from '@common/components/DependenciesAlert';
import PanelCard from '@common/components/PanelCard';
import PanelStack from '@common/components/PanelCard/PanelStack';

const STATUS_QUERY_KEY = 'wireshark-moduleStatus';
const POLL_INTERVAL_MS = 5000;

/**
 * Wireshark module panel: start/stop rpcapd, libpcap's remote-capture daemon. No
 * capture/analysis binary of any kind runs on the router -- real desktop Wireshark
 * connects to rpcapd directly over the network and picks its own interface once
 * connected, so this panel never needs (or offers) an interface list of its own.
 */
const WiresharkPage = () => {
    const queryClient = useQueryClient();

    const { data: status, isLoading } = useQuery({
        queryKey: [STATUS_QUERY_KEY],
        queryFn: () => fetchPost({ module: 'wireshark', action: 'moduleStatus' }),
        refetchInterval: POLL_INTERVAL_MS,
    });

    const startMutation = useMutation({
        mutationFn: () => fetchPost({ module: 'wireshark', action: 'startDaemon' }),
        onSuccess: () => {
            toast.success('rpcapd started');
            queryClient.invalidateQueries({ queryKey: [STATUS_QUERY_KEY] });
        },
        onError: (error) => toast.error(error.message),
    });

    const stopMutation = useMutation({
        mutationFn: () => fetchPost({ module: 'wireshark', action: 'stopDaemon' }),
        onSuccess: () => {
            toast.success('rpcapd stopped');
            queryClient.invalidateQueries({ queryKey: [STATUS_QUERY_KEY] });
        },
        onError: (error) => toast.error(error.message),
    });

    if (isLoading) {
        return <PanelCard title="Wireshark">Loading...</PanelCard>;
    }

    if (!status?.hasDependencies) {
        return (
            <PanelCard title="Wireshark">
                <DependenciesAlert
                    module={'wireshark'}
                    dependenciesQueryKey={STATUS_QUERY_KEY}
                    show={true}
                    message={status?.message}
                    internalAvailable={status?.internalAvailable}
                    SDAvailable={status?.SDAvailable}
                />
            </PanelCard>
        );
    }

    const isRunning = status.isRunning;
    const port = status.port;
    const routerHost = window.location.hostname;

    return (
        <PanelStack>
            <PanelCard title="Wireshark (Remote Capture)">
                <p>
                    Status:{' '}
                    <strong className={isRunning ? 'text-success' : 'text-secondary'}>
                        {isRunning ? 'Running' : 'Stopped'}
                    </strong>
                </p>

                <div className="d-flex gap-2 mb-3">
                    {!isRunning && (
                        <button
                            className="btn btn-primary"
                            disabled={startMutation.isPending}
                            onClick={() => startMutation.mutate()}
                        >
                            Start
                        </button>
                    )}
                    {isRunning && (
                        <button
                            className="btn btn-danger"
                            disabled={stopMutation.isPending}
                            onClick={() => stopMutation.mutate()}
                        >
                            Stop
                        </button>
                    )}
                </div>

                {isRunning && (
                    <div className="alert alert-info mb-0">
                        <p className="mb-2">
                            This doesn&apos;t run Wireshark on the router &mdash; it runs{' '}
                            <code>rpcapd</code>, libpcap&apos;s remote-capture daemon, which the real
                            Wireshark app on your own computer connects to directly over the network.
                        </p>
                        <p className="mb-0">
                            In Wireshark: <strong>Capture &rarr; Options &rarr; Manage Interfaces &rarr; Remote</strong>{' '}
                            tab &rarr; <strong>+</strong> &rarr; Host: <code>{routerHost}</code>, Port:{' '}
                            <code>{port}</code>. Wireshark will then list this router&apos;s own capture
                            interfaces for you to pick from &mdash; nothing to choose here.
                        </p>
                    </div>
                )}

                {!isRunning && (
                    <p className="text-muted small mb-0">
                        No authentication is used on the capture port (same trust model as this
                        panel&apos;s other exposed services) &mdash; anyone who can reach the router on
                        this port while it&apos;s running can capture traffic.
                    </p>
                )}
            </PanelCard>
        </PanelStack>
    );
};

export default WiresharkPage;
