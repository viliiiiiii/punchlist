import React, { useMemo, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { AlertCircle, Download, LayoutDashboard, ListChecks, Settings as SettingsIcon, Building2, Kanban } from 'lucide-react';
import TasksView from './components/TasksView.jsx';
import RoomsView from './components/RoomsView.jsx';
import BoardView from './components/BoardView.jsx';
import DashboardView from './components/DashboardView.jsx';
import SettingsView from './components/SettingsView.jsx';
import TaskModal from './components/TaskModal.jsx';
import { useTasks } from './context/TaskContext.jsx';
import { exportAllTasks, exportBuildingTasks } from './utils/pdf.js';

const tabs = [
  { id: 'rooms', label: 'Rooms', icon: ListChecks },
  { id: 'board', label: 'Board', icon: TableProperties },
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'settings', label: 'Settings', icon: SettingsIcon },
];

export default function App() {
  const { tasks } = useTasks();
  const [activeTab, setActiveTab] = useState('rooms');
  const [modalTask, setModalTask] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [buildingFilter, setBuildingFilter] = useState('all');

  const stats = useMemo(() => ({
    total: tasks.length,
    open: tasks.filter((t) => t.status === 'open').length,
    in_progress: tasks.filter((t) => t.status === 'in_progress').length,
    done: tasks.filter((t) => t.status === 'done').length,
  }), [tasks]);

  const handleCreate = () => {
    setModalTask(null);
    setModalOpen(true);
  };

  const handleEdit = (task) => {
    setModalTask(task);
    setModalOpen(true);
  };

  const handleExportBuilding = async () => {
    if (buildingFilter === 'all') return;
    await exportBuildingTasks(buildingFilter, tasks);
  };

  const handleExportAll = async () => {
    await exportAllTasks(tasks);
  };

  const ActiveView = () => {
    switch (activeTab) {
      case 'rooms':
        return (
          <RoomsView
            onCreate={handleCreate}
            onEdit={handleEdit}
            buildingFilter={buildingFilter}
            setBuildingFilter={setBuildingFilter}
          />
        );
      case 'rooms':
        return <RoomsView onEdit={handleEdit} onPhotoPreview={handlePreviewPhoto} />;
      case 'board':
        return <BoardView onEdit={handleEdit} onPhotoPreview={handlePreviewPhoto} />;
      case 'dashboard':
        return <DashboardView stats={stats} />;
      case 'settings':
        return <SettingsView />;
      default:
        return null;
    }
  };

  return (
    <div className="app-shell">
      <header className="app-header">
        <div className="logo">Punch List</div>
        <div className="header-actions">
          {activeTab === 'tasks' && (
            <>
              <button className="primary" onClick={handleCreate}>New Task</button>
              <button className="ghost" onClick={handleExportAll} title="Export All Tasks">
                <Download size={18} />
                <span>Export All</span>
              </button>
              <button
                className="ghost"
                onClick={handleExportBuilding}
                disabled={buildingFilter === 'all'}
                title="Export Building"
              >
                <Download size={18} />
                <span>Export Building</span>
              </button>
            </>
          )}
          {activeTab === 'board' && (
            <button className="primary" onClick={handleCreate}>New Task</button>
          )}
        </div>
      </header>
      <nav className="tab-bar">
        {tabs.map((tab) => {
          const Icon = tab.icon;
          return (
            <button
              key={tab.id}
              className={tab.id === activeTab ? 'tab active' : 'tab'}
              onClick={() => setActiveTab(tab.id)}
            >
              <Icon size={18} />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </nav>
      <main className="content">
        <AnimatePresence mode="wait">
          <motion.div
            key={activeTab}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -8 }}
            transition={{ duration: 0.2 }}
            className="tab-panel"
          >
            <ActiveView />
          </motion.div>
        </AnimatePresence>
      </main>
      <footer className="app-footer">
        <AlertCircle size={16} />
        <span>Data stored locally. Remember to export before clearing.</span>
      </footer>
      <TaskModal
        open={modalOpen}
        onClose={() => {
          setModalOpen(false);
          setModalTask(null);
        }}
        task={modalTask}
      />
      {previewPhoto && (
        <div className="photo-preview-backdrop" onClick={() => setPreviewPhoto(null)}>
          <div className="photo-preview-content" onClick={(event) => event.stopPropagation()}>
            <img src={previewPhoto.src} alt="Task attachment" />
            {previewPhoto.name && <span className="preview-caption">{previewPhoto.name}</span>}
            <button className="ghost" onClick={() => setPreviewPhoto(null)}>Close</button>
          </div>
        </div>
      )}
    </div>
  );
}
