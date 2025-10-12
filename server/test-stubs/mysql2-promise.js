const createPool = () => ({
  query: async () => {
    throw new Error('Stub pool query called');
  },
  execute: async () => {
    throw new Error('Stub pool execute called');
  },
  end: async () => {},
  getConnection: async () => ({
    beginTransaction: async () => {},
    execute: async () => {
      throw new Error('Stub connection execute called');
    },
    commit: async () => {},
    rollback: async () => {},
    release: () => {},
  }),
});

export default {
  createPool,
};
