import amqp from "amqplib";

const AMQP_URL = process.env.AMQP_URL || "amqp://guest:guest@127.0.0.1:5672";
const EXCHANGE = "app.tasks";
const QUEUE = "app.tasks.default";

let connectionPromise;
let channelPromise;

async function getConnection() {
  if (!connectionPromise) {
    connectionPromise = amqp.connect(AMQP_URL);
  }
  return connectionPromise;
}

async function getChannel() {
  if (!channelPromise) {
    channelPromise = (async () => {
      const conn = await getConnection();
      const channel = await conn.createChannel();
      await channel.assertExchange(EXCHANGE, "direct", { durable: true });
      await channel.assertQueue(QUEUE, { durable: true });
      await channel.bindQueue(QUEUE, EXCHANGE, "#");
      return channel;
    })();
  }
  return channelPromise;
}

export async function publishTask({ taskId, type, payload }) {
  const channel = await getChannel();
  const body = Buffer.from(JSON.stringify({ taskId, type, payload }));
  channel.publish(EXCHANGE, type || "task", body, { persistent: true });
}

export async function withQueueConsumer(handler) {
  const channel = await getChannel();
  await channel.prefetch(1);
  await channel.consume(
    QUEUE,
    async (msg) => {
      if (!msg) return;
      try {
        const payload = JSON.parse(msg.content.toString());
        await handler(payload, {
          ack: () => channel.ack(msg),
          nack: ({ requeue } = { requeue: false }) => channel.nack(msg, false, requeue),
        });
      } catch (err) {
        console.error("Failed to process task message", err);
        channel.nack(msg, false, false);
      }
    },
    { noAck: false }
  );
}

export async function closeQueue() {
  if (channelPromise) {
    const channel = await channelPromise;
    await channel.close();
    channelPromise = undefined;
  }
  if (connectionPromise) {
    const conn = await connectionPromise;
    await conn.close();
    connectionPromise = undefined;
  }
}

export { EXCHANGE, QUEUE };
