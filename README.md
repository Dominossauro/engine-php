# Dominossauro Engine

## Description

The **Dominossauro Engine** is the core low-code flow processing and orchestration component of the Dominossauro platform. This component is responsible for interpreting and executing visual flows defined through nodes, enabling the creation of APIs and business logic without the need to manually write code.

## What is it?

The Engine is a node-based flow processor that transforms JSON definitions into executable logic. It functions as the "brain" of the Dominossauro platform, coordinating the execution of different types of nodes (variables, validations, loops, HTTP requests, database operations, etc.) in a sequential and conditional manner.

## What is it for?

The Engine enables:

- **Low-Code Flow Processing**: Interprets and executes visual flows defined through interconnected nodes
- **API Orchestration**: Manages HTTP endpoints and routes requests to appropriate flows
- **Context Management**: Maintains variables and state during flow execution through `FlowContext`
- **Data Validation**: Processes complex validation rules with multiple verification types
- **Loops and Iterations**: Supports repetition structures over arrays and data collections
- **Custom Nodes**: Allows extension through custom nodes created by developers
- **Logging and Debugging**: Records flow execution for analysis and debugging

## Main Components

### Engine
Main class that coordinates the entire system. Manages controller registration, endpoints, and HTTP request processing.

### FlowProcessor
Responsible for executing node flows, finding endpoints matching requests, and orchestrating sequential node execution.

### FlowContext
Manages execution context, storing variables and data that can be shared between different nodes during a flow.

### FlowLog
Logging system that records events and actions during flow execution, facilitating debugging and monitoring.

### LowCodeAPI
High-level API for validation and manipulation of endpoint and flow definitions in JSON format.

### LowCodeMiddleware
Middleware that intercepts HTTP requests and directs them to proper processing by the Engine.

### CustomNodeHandler
Manager that enables registration and execution of custom nodes created by developers.

## System Nodes

The Engine includes several built-in nodes:

- **NodeVariable**: Creation and manipulation of variables in the flow context
- **NodeValidation**: Data validation with support for multiple rules
- **NodeLoop**: Iteration over arrays and collections
- **NodeSetVariableValue**: Assignment of values to existing variables

In addition to internal nodes, the Engine integrates with nodes from other Dominossauro packages:
- **NodeGet**, **NodeQuery**, **NodeAuth** (from Router)
- **NodeResponse** (from HttpResponse)

## Integration with Dominossauro Ecosystem

The Engine works together with several other platform components:

- **dominossauro/router**: HTTP route and endpoint management
- **dominossauro/httpresponse**: HTTP response formatting and sending
- **dominossauro/httprequest**: HTTP request processing
- **dominossauro/baserepository**: Data access and repositories
- **dominossauro/sqlgenerator**: Automatic SQL query generation
- **dominossauro/app**: Main application and entry point

## Requirements

- PHP >= 8.0
- dominossauro/sqlgenerator ^1.0

## License

Proprietary - All rights reserved © Dominossauro