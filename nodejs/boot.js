const initTracer = require("jaeger-client").initTracer
const opentracing = require("opentracing")
const amqp = require('amqplib')
const dotenv = require('dotenv')

async function boot(process) {
    const tracer = initTracer({
        serviceName: process,
        reporter: {
            // collectorEndpoint: 'http://localhost:14268',
            agentHost: 'localhost',
            agentPort: 6832,
            flushIntervalMs: 10,
            logSpans: true,
        },
        sampler: {
            type: 'const',
            param: 1,
            // type: 'probabilistic',
            // param: 0.1,
        },
    }, {
        logger: {
            info: function logInfo(msg) {
                console.log('INFO ', msg)
            },
            error: function logError(msg) {
                console.log('ERROR', msg)
            }
        }
    })
    opentracing.initGlobalTracer(tracer);

    const appSpan = tracer.startSpan('process')

    dotenv.config({path:'../'})
    console.log(process.env)

    const span = tracer.startSpan('rabbitmq-connect', {childOf: appSpan})
    const conn = await amqp.connect('amqp://localhost')
    const channel = await conn.createChannel()
    span.finish()

    return [tracer, channel, appSpan]
}

exports.boot = boot