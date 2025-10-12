import React, { createContext, useContext, useEffect, useMemo, useReducer, useState } from 'react';

const STORAGE_KEY = 'punch-list-tasks';
const SETTINGS_KEY = 'punch-list-settings';

const TaskContext = createContext();

const defaultSettings = {
  presignEndpoint: '',
};

function taskReducer(state, action) {
  switch (action.type) {
    case 'set':
      return [...action.payload];
    case 'add':
      return [action.payload, ...state];
    case 'update':
      return state.map((task) => (task.id === action.payload.id ? action.payload : task));
    case 'delete':
      return state.filter((task) => task.id !== action.payload);
    case 'duplicate':
      return [action.payload, ...state];
    case 'clear':
      return [];
    default:
      return state;
  }
}

function buildDemoTasks() {
  const now = new Date().toISOString();
  return [
    {
      id: 'demo-1',
      title: 'Paint scuff on wall',
      building: 'A',
      room: '1205',
      section: 'Living',
      description: 'Touch up paint on north wall',
      severity: 'medium',
      assignee: 'Jamie',
      dueDate: '2024-04-05',
      status: 'open',
      photos: [],
      createdAt: now,
      updatedAt: now,
    },
    {
      id: 'demo-2',
      title: 'Replace door handle',
      building: 'A',
      room: '1205',
      section: 'Entry',
      description: 'Handle is loose',
      severity: 'high',
      assignee: 'Morgan',
      dueDate: '2024-04-07',
      status: 'in_progress',
      photos: [],
      createdAt: now,
      updatedAt: now,
    },
    {
      id: 'demo-3',
      title: 'Clean balcony glass',
      building: 'B',
      room: '905',
      section: 'Balcony',
      description: 'Smudges on railing glass',
      severity: 'low',
      assignee: 'Taylor',
      dueDate: '2024-04-03',
      status: 'done',
      photos: [],
      createdAt: now,
      updatedAt: now,
    },
  ];
}

export function TaskProvider({ children }) {
  const [tasks, dispatch] = useReducer(taskReducer, [], () => {
    if (typeof window === 'undefined') return [];
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
      console.error('Failed to parse stored tasks', err);
      return [];
    }
  });

  const [settings, setSettings] = useState(() => {
    if (typeof window === 'undefined') return defaultSettings;
    try {
      const raw = window.localStorage.getItem(SETTINGS_KEY);
      if (!raw) return defaultSettings;
      const parsed = JSON.parse(raw);
      return { ...defaultSettings, ...parsed };
    } catch (err) {
      console.error('Failed to parse settings', err);
      return defaultSettings;
    }
  });

  useEffect(() => {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(tasks));
  }, [tasks]);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
  }, [settings]);

  const value = useMemo(
    () => ({
      tasks,
      settings,
      setSettings: (partial) => setSettings((prev) => ({ ...prev, ...partial })),
      addTask: (task) => dispatch({ type: 'add', payload: task }),
      updateTask: (task) => dispatch({ type: 'update', payload: task }),
      deleteTask: (id) => dispatch({ type: 'delete', payload: id }),
      duplicateTask: (task) => dispatch({ type: 'duplicate', payload: task }),
      setTasks: (all) => dispatch({ type: 'set', payload: all }),
      clearTasks: () => dispatch({ type: 'clear' }),
      seedDemo: () => dispatch({ type: 'set', payload: buildDemoTasks() }),
    }),
    [tasks, settings]
  );

  return <TaskContext.Provider value={value}>{children}</TaskContext.Provider>;
}

export function useTasks() {
  const ctx = useContext(TaskContext);
  if (!ctx) throw new Error('useTasks must be used inside TaskProvider');
  return ctx;
}
