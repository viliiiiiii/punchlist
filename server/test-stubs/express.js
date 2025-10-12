export const Router = () => ({
  post: () => {},
  get: () => {},
  use: () => {},
});

const express = () => ({
  use: () => {},
  get: () => {},
  post: () => {},
  listen: () => ({
    close: () => {},
  }),
});

express.Router = Router;
express.json = () => () => {};

export default express;
