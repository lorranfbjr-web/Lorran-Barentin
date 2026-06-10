import React, { useState } from 'react';
import Header from './components/Header';
import FilterBar from './components/FilterBar';
import SearchBar from './components/SearchBar';
import NewsFeed from './components/NewsFeed';
import SourcesPanel from './components/SourcesPanel';
import RadarPanel from './components/RadarPanel';

type Tab = 'feed' | 'sources' | 'radar';

export default function App() {
    const [tab, setTab] = useState<Tab>('feed');
    const [filters, setFilters] = useState({
        q: '',
        source_id: '',
        region: '',
        theme_id: '',
        period: '24h',
        urgency: '',
    });

    return (
        <div className="min-h-screen">
            <Header activeTab={tab} onTabChange={setTab} />

            {tab === 'feed' ? (
                <main className="max-w-7xl mx-auto px-4 py-6">
                    <div className="flex flex-col md:flex-row gap-4 mb-6">
                        <SearchBar
                            value={filters.q}
                            onChange={(q) => setFilters((f) => ({ ...f, q }))}
                        />
                        <FilterBar filters={filters} onChange={setFilters} />
                    </div>
                    <NewsFeed filters={filters} />
                </main>
            ) : tab === 'radar' ? (
                <main className="max-w-7xl mx-auto px-4 py-6">
                    <RadarPanel />
                </main>
            ) : (
                <main className="max-w-7xl mx-auto px-4 py-6">
                    <SourcesPanel />
                </main>
            )}
        </div>
    );
}
