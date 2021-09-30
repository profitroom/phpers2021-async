const opentracing = require("opentracing");
const boot = require('./boot').boot

async function main() {
    const [tracer, channel, appSpan] = await boot('producer')

    const span = tracer.startSpan('producing', {childOf: appSpan})
    await channel.assertQueue('message_queue')
    const headers = []
    tracer.inject(span, opentracing.FORMAT_HTTP_HEADERS, headers)

    for (let i = 0; i < 10000; i++) {
        await channel.sendToQueue('message_queue', Buffer.from(JSON.stringify({
            id: Math.round(Math.random() * 1000 + 1),
            value: Math.round(Math.random() * 89 + 10),
        })), {
            headers: headers
        })
    }

    span.finish()
    await channel.close()
    appSpan.finish()
    tracer.close()
}

main()//.then(() => process.exit())